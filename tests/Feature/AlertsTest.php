<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Psr\Log\AbstractLogger;
use Illuminate\Support\Stringable;
use Illuminate\Contracts\Cache\Repository;
use Simtabi\Laranail\Settings\Data\Subject;
use Illuminate\Support\Facades\Notification;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\AlertRule;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AlertContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Simtabi\Laranail\Settings\Events\AuditPruned;
use Illuminate\Contracts\Notifications\Dispatcher;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Simtabi\Laranail\Settings\Events\StorePromoted;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Listeners\LogSettingsEvent;
use Simtabi\Laranail\Settings\Listeners\SendSettingsAlert;
use Simtabi\Laranail\Settings\Notifications\SettingsAlert;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Alerts: Laravel notifications on settings events, off by default, matched by rules, throttled,
 * and never carrying a value.
 */

/** Turn alerts on with these rules and register the listeners as the provider does at boot. */
function alertOn(array $rules, array $extra = []): void
{
    config()->set('laranail.settings.alerts.enabled', true);
    config()->set('laranail.settings.alerts.rules', $rules);

    foreach ($extra as $key => $value) {
        config()->set('laranail.settings.' . $key, $value);
    }

    app()->getProvider(SettingsServiceProvider::class)?->packageBooted();
}

/** @return list<SettingsAlert> every alert sent on demand (to addresses rather than a model) */
function sentAlerts(): array
{
    return Notification::sent(new AnonymousNotifiable, SettingsAlert::class)->all();
}

it('registers no alert listener unless alerts are enabled', function (): void {
    $raw = app('events')->getRawListeners();

    expect(in_array(SendSettingsAlert::class, $raw[SettingSaved::class] ?? [], true))->toBeFalse()
        ->and(in_array(LogSettingsEvent::class, $raw[SettingSaved::class] ?? [], true))->toBeFalse();

    alertOn([], ['listeners.alerts' => false]);

    expect(in_array(SendSettingsAlert::class, app('events')->getRawListeners()[SettingSaved::class] ?? [], true))->toBeFalse();
});

it('mails an alert for a matching change, with identifiers only', function (): void {
    Notification::fake();
    alertOn([['events' => ['SettingSaved'], 'keys' => ['mail.*'], 'notify' => ['mail' => ['ops@example.test']]]]);

    settings()->as(user(3))->asSystem('nightly')->set('mail.host', 'smtp.example.test');
    settings()->set('site.name', 'Acme');

    Notification::assertSentOnDemand(SettingsAlert::class, static fn (SettingsAlert $alert, array $channels, object $notifiable): bool => $channels === ['mail']
        && $notifiable->routes['mail'] === ['ops@example.test']
        && $alert->context->key === 'mail.host'
        && $alert->context->event === 'SettingSaved'
        && $alert->context->outcome === 'allowed');
    Notification::assertSentOnDemandTimes(SettingsAlert::class, 1);
});

it('throttles a rule per key, and alerts again for another key', function (): void {
    Notification::fake();
    alertOn([['events' => [SettingSaved::class], 'notify' => ['mail' => ['ops@example.test']]]], ['alerts.cache' => 'array']);

    settings()->set('site.name', 'one');
    settings()->set('site.name', 'two');
    settings()->set('site.url', 'https://example.test');

    Notification::assertSentOnDemandTimes(SettingsAlert::class, 2);
});

it('does not throttle when the throttle is zero', function (): void {
    Notification::fake();
    alertOn([['events' => ['SettingSaved'], 'notify' => ['mail' => ['ops@example.test']]]], ['alerts.throttle' => 0]);

    settings()->set('site.name', 'one');
    settings()->set('site.name', 'two');

    Notification::assertSentOnDemandTimes(SettingsAlert::class, 2);
});

it('sends anyway when the throttle cache fails, and logs it', function (): void {
    Notification::fake();
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    $logger = alertLogger();
    $listener = new SendSettingsAlert(app(Dispatcher::class), [AlertRule::fromConfig(['events' => ['SettingSaved'], 'notify' => ['mail' => ['ops@example.test']]], 0)], static fn (): bool => false, static fn (): CarbonImmutable => now()->toImmutable(), $logger, $cache, 60);

    $listener->handle(new SettingSaved('settings', 'global', 'site.name', '', 1));

    Notification::assertSentOnDemandTimes(SettingsAlert::class, 1);
    expect($logger->messages)->toHaveCount(1)
        ->and($logger->messages[0])->toContain('cache down');
});

it('narrows by store, sensitive keys and outcome', function (): void {
    Notification::fake();
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    alertOn([
        ['events' => ['SettingSaved'], 'stores' => ['billing'], 'notify' => ['mail' => ['billing@example.test']]],
        ['events' => ['SettingSaved'], 'sensitive_only' => true, 'notify' => ['mail' => ['security@example.test']]],
        ['events' => ['AccessWasDenied', 'SettingSaved'], 'outcomes' => ['denied'], 'notify' => ['mail' => ['denied@example.test']]],
    ]);

    settings()->set('site.name', 'Acme');
    settings()->set('mail.password', TestCredentials::secret());

    expect(fn () => settings()->as(user(4))->set('site.name', 'x'))->toThrow(AccessDenied::class);

    $routes = array_map(static fn (SettingsAlert $alert): string => $alert->context->event . ':' . ($alert->context->key ?? '-') . ':' . $alert->context->outcome, sentAlerts());

    expect($routes)->toBe(['SettingSaved:mail.password:allowed', 'AccessWasDenied:site.name:denied']);

    $denied = sentAlerts()[1]->context;

    expect($denied->actor)->toStartWith('user:')->toEndWith(':4')
        ->and($denied->sensitive)->toBeFalse()
        ->and($denied->details)->toMatchArray(['ability' => 'update', 'refusal' => 'access_denied']);
});

it('queues the alert on the rule\'s queue', function (): void {
    Notification::fake();
    alertOn([['events' => ['SettingLocked'], 'notify' => ['mail' => ['ops@example.test']], 'queue' => 'alerts']]);

    settings()->set('site.name', 'Acme');
    settings()->lock('site.name');

    $alert = sentAlerts()[0];

    expect($alert)->toBeInstanceOf(SettingsAlert::class)
        ->and($alert->queue)->toBe('alerts')
        ->and($alert->context->event)->toBe('SettingLocked')
        ->and($alert->context->details)->toBe(['version' => 2]);
});

it('routes to Slack only when the Slack channel package is installed', function (): void {
    Notification::fake();
    alertOn([['events' => ['SettingSaved'], 'notify' => ['mail' => ['ops@example.test'], 'slack' => 'https://hooks.slack.test/T/B/C']]]);
    $installed = class_exists(SettingsAlert::SLACK_MESSAGE);

    settings()->set('site.name', 'Acme');

    expect(sentAlerts()[0]->channels)->toBe($installed ? ['mail', 'slack'] : ['mail']);

    require_once __DIR__ . '/../Fixtures/Stubs/legacy-slack-message.php';
    settings()->set('site.url', 'https://example.test');

    $slack = sentAlerts()[1];
    $message = $slack->toSlack(new AnonymousNotifiable);

    expect($slack->channels)->toBe(['mail', 'slack'])
        ->and($message?->content ?? null)->toContain('Setting Saved')->toContain('site.url')
        ->and(new SettingsAlert($slack->context, ['slack'], 'Nope\\NoSuchMessage')->toSlack(new AnonymousNotifiable))->toBeNull();
});

it('never puts a setting\'s value into any channel of an alert', function (): void {
    Notification::fake();
    require_once __DIR__ . '/../Fixtures/Stubs/legacy-slack-message.php';
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    alertOn([['events' => SendSettingsAlert::EVENTS, 'notify' => ['mail' => ['ops@example.test'], 'slack' => 'https://hooks.slack.test/T/B/C']]], ['alerts.throttle' => 0]);
    $secret = TestCredentials::secret('alert');

    settings()->because('rotate')->set('mail.password', $secret);
    settings()->lock('mail.password');
    expect(fn (): mixed => settings()->as(user())->get('mail.password'))->toThrow(AccessDenied::class);

    $alerts = sentAlerts();
    $rendered = '';
    $notifiable = new AnonymousNotifiable;

    foreach ($alerts as $alert) {
        $mail = $alert->toMail($notifiable);
        $rendered .= $mail->subject . json_encode($mail->toArray()) . $mail->render()
            . json_encode($alert->toArray($notifiable)) . json_encode($alert->toDatabase($notifiable))
            . json_encode($alert->toSlack($notifiable)) . serialize($alert);
    }

    // Three alerts (saved, locked, denied), each rendered on every channel; none holds the secret.
    expect($alerts)->toHaveCount(3)
        ->and(strlen($rendered))->toBeGreaterThan(3000)
        ->and($rendered)->toContain('mail.password')
        ->and($rendered)->not->toContain($secret);
});

it('rejects a malformed rule with its config path', function (array $rule, string $message): void {
    alertOn([$rule]);

    expect(fn () => app(SendSettingsAlert::class))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'no events'          => [['events' => []], 'rules.0.events: name at least one event'],
    'events not a list'  => [['events' => 'SettingSaved'], 'rules.0.events: a list of strings.'],
    'bad keys'           => [['events' => ['SettingSaved'], 'keys' => [1]], 'rules.0.keys: a list of strings, or null'],
    'bad sensitive'      => [['events' => ['SettingSaved'], 'sensitive_only' => 'yes'], 'rules.0.sensitive_only'],
    'bad notify'         => [['events' => ['SettingSaved'], 'notify' => 'ops@example.test'], 'rules.0.notify'],
    'bad slack'          => [['events' => ['SettingSaved'], 'notify' => ['slack' => '']], 'rules.0.notify.slack'],
    'bad queue'          => [['events' => ['SettingSaved'], 'queue' => 5], 'rules.0.queue'],
    'bad database model' => [['events' => ['SettingSaved'], 'notify' => ['database' => ['type' => stdClass::class, 'id' => 1]]], 'rules.0.notify.database'],
]);

it('rejects a rule that is not an array', function (): void {
    alertOn(['nonsense']);

    expect(fn () => app(SendSettingsAlert::class))->toThrow(InvalidConfiguration::class, 'rules.0: each rule is an array');
});

it('names the event in a headline, with the key when there is one', function (): void {
    $at = now()->toImmutable();

    expect(AlertContext::fromEvent(new SettingSaved('settings', 'global', 'site.name', '', 1), false, $at)->headline())->toBe('laranail/settings: Setting Saved, [site.name] in store settings')
        ->and(AlertContext::fromEvent(new AuditPruned(3, 30), false, $at)->headline())->toBe('laranail/settings: Audit Pruned')
        ->and(AlertContext::fromEvent(new StorePromoted('settings', 1, 0, null), false, $at)->headline())->toBe('laranail/settings: Store Promoted in store settings');
});

it('logs every settings event when the log listener is on', function (): void {
    alertOn([], ['listeners.log' => true, 'alerts.enabled' => false]);
    $logger = alertLogger();
    $listener = new LogSettingsEvent($logger);

    expect(in_array(LogSettingsEvent::class, app('events')->getRawListeners()[SettingSaved::class] ?? [], true))->toBeTrue();

    $listener->handle(new SettingSaved('settings', 'global', 'site.name', '', 2));
    $listener->handle(new AccessWasDenied(Ability::View, new Subject(new StoreName('settings')), null, 'actor_required'));

    expect($logger->messages)->toBe([
        'info laranail/settings event: SettingSaved {"store":"settings","scope":"global","key":"site.name","locale":"","version":2}',
        'warning laranail/settings event: AccessWasDenied {"ability":"view","store":"settings","scope":"global","key":null,"locale":"","category":null,"actor":null,"refusal":"actor_required","reason":null}',
    ]);
});

function alertLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable|\Stringable $message, array $context = []): void
        {
            $this->messages[] = trim(sprintf('%s %s %s', is_string($level) ? $level : '', $message, $context === [] ? '' : json_encode($context)));
        }
    };
}
