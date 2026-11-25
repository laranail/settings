<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Notifications\SettingsAlert;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;

/*
 * Alerts to a database notifiable: the rule names a model and its key; a model that no longer
 * exists is logged and skipped rather than failing the write.
 */

beforeEach(function (): void {
    useDatabase(ownerTables: ['test_users' => 'bigint']);
    DB::table('test_users')->insert(['id' => 1, 'name' => 'Ada']);
});

function databaseAlerts(int|string $id, ?string $queue = null): void
{
    config()->set('laranail.settings.alerts.enabled', true);
    config()->set('laranail.settings.alerts.rules', [[
        'events' => ['SettingSaved'],
        'notify' => ['database' => ['type' => TestUser::class, 'id' => $id]],
        'queue'  => $queue,
    ]]);
    app()->getProvider(SettingsServiceProvider::class)?->packageBooted();
}

it('notifies the database notifiable a rule names', function (): void {
    Notification::fake();
    databaseAlerts(1, 'alerts');

    settings()->set('site.name', 'Acme');

    Notification::assertSentTo(TestUser::query()->findOrFail(1), SettingsAlert::class, static fn (SettingsAlert $alert, array $channels): bool => $channels === ['database']
        && $alert->queue === 'alerts'
        && $alert->toDatabase(new stdClass)['key'] === 'site.name');
    Notification::assertNothingSentTo(new AnonymousNotifiable);
});

it('skips a database notifiable that does not exist, and the write still succeeds', function (): void {
    Notification::fake();
    databaseAlerts(99);

    settings()->set('site.name', 'Acme');

    Notification::assertNothingSent();
    expect(settings()->get('site.name'))->toBe('Acme');
});

it('logs an alert that cannot be sent instead of failing the write', function (): void {
    Notification::fake();
    $logged = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = $level . ' ' . $message;
        }
    };
    settings()->configuration()->logTo($logged);
    databaseAlerts(1);
    Schema::drop('test_users');

    settings()->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme');
    Notification::assertNothingSent();
    expect($logged->messages)->toHaveCount(1)
        ->and($logged->messages[0])->toStartWith('error laranail/settings: alert rule 0 could not be sent');
});

it('sends nothing when alerts are on but no rule is configured', function (): void {
    Notification::fake();
    config()->set('laranail.settings.alerts.enabled', true);
    app()->getProvider(SettingsServiceProvider::class)?->packageBooted();

    settings()->set('site.name', 'Acme');

    Notification::assertNothingSent();
});
