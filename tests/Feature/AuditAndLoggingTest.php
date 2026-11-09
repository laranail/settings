<?php

declare(strict_types=1);

use Monolog\LogRecord;
use Psr\Log\NullLogger;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;
use Monolog\Handler\TestHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\Actor;
use PHPUnit\Framework\AssertionFailedError;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Data\ActorContext;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/** Point the package log at a Monolog test handler and return the handler. */
function captureLog(): TestHandler
{
    config()->set('logging.channels.capture', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    config()->set('laranail.settings.logging.channel', 'capture');

    $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
    assert($handler instanceof TestHandler);

    return $handler;
}

/** @return list<AuditEntry> entries for one action */
function entriesFor(InMemoryAuditSink $sink, AuditAction $action): array
{
    return array_values(array_filter($sink->entries(), static fn (AuditEntry $entry): bool => $entry->action === $action));
}

it('audits every allowed write, forget, lock and unlock with versions and never a value', function (): void {
    config()->set('laranail.settings.audit.sinks', ['null']);
    $sink = auditInMemory();

    settings()->because('INC-7')->set('site.name', 'a-secret-value');
    settings()->lock('site.name');
    settings()->unlock('site.name');
    settings()->forget('site.name');

    $update = entriesFor($sink, AuditAction::Update)[0];

    expect($update->outcome)->toBe(AuditOutcome::Allowed)
        ->and($update->store)->toBe('settings')
        ->and($update->scope)->toBe('global')
        ->and($update->key)->toBe('site.name')
        ->and($update->versionFrom)->toBeNull()
        ->and($update->versionTo)->toBe(1)
        ->and($update->actorType->value)->toBe('system')
        ->and($update->reason)->toBe('INC-7')
        ->and(entriesFor($sink, AuditAction::Lock)[0]->versionTo)->toBe(2)
        ->and(entriesFor($sink, AuditAction::Unlock)[0]->versionFrom)->toBe(2)
        ->and(entriesFor($sink, AuditAction::Forget)[0]->versionFrom)->toBe(3)
        ->and(entriesFor($sink, AuditAction::Forget)[0]->versionTo)->toBeNull()
        ->and(json_encode(array_map(static fn (AuditEntry $entry): array => $entry->toArray(), $sink->entries())))->not->toContain('a-secret-value');
});

it('audits a denial with the actor and request context, and never the value', function (): void {
    $sink = auditInMemory();
    Gate::define('laranail-settings.update', static fn (TestUser $user): bool => false);
    $actor = Actor::user(user(9), context: new ActorContext('10.0.0.9', 'tests', 'req-9'));

    expect(fn () => settings()->as($actor)->because('INC-1')->set('site.name', 'a-secret-value'))->toThrow(AccessDenied::class);

    $denied = entriesFor($sink, AuditAction::Update)[0];

    expect($denied->outcome)->toBe(AuditOutcome::Denied)
        ->and($denied->actorType->value)->toBe('user')
        ->and($denied->actorId)->toBe('9')
        ->and($denied->ip)->toBe('10.0.0.9')
        ->and($denied->userAgent)->toBe('tests')
        ->and($denied->requestId)->toBe('req-9')
        ->and($denied->reason)->toBe('INC-1')
        ->and($denied->meta)->toBe(['refusal' => 'access_denied', 'detail' => 'gate [laranail-settings.update] denied'])
        ->and(json_encode($denied->toArray()))->not->toContain('a-secret-value');
});

it('audits a write that fails validation as failed and rethrows', function (): void {
    app(DefinitionRegistry::class)->define('limits.seats')->rules(['integer'])->register();
    $sink = auditInMemory();

    expect(fn () => settings()->set('limits.seats', 'many'))->toThrow(InvalidSettingValue::class);

    expect(entriesFor($sink, AuditAction::Update)[0]->outcome)->toBe(AuditOutcome::Failed)
        ->and(entriesFor($sink, AuditAction::Update)[0]->meta['error'])->toBe(InvalidSettingValue::class);
});

it('does not audit reads', function (): void {
    $sink = auditInMemory();

    settings()->get('site.name');

    expect(entriesFor($sink, AuditAction::View))->toBe([]);
});

it('stamps entries with a ulid and the current time', function (): void {
    Str::createUlidsUsing(static fn (): Ulid => new Ulid('01JABCDEFGHJKMNPQRSTVWXYZ0'));
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
    $sink = auditInMemory();

    settings()->set('site.name', 'Acme');

    $entry = entriesFor($sink, AuditAction::Update)[0];

    expect($entry->id)->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0')
        ->and($entry->occurredAt->toDateTimeString())->toBe('2026-11-09 12:00:00');

    Str::createUlidsNormally();
});

it('writes audit entries to the log channel through the log sink', function (): void {
    $handler = captureLog();

    settings()->set('site.name', 'a-secret-value');

    expect($handler->hasInfoThatContains('laranail/settings audit: update allowed'))->toBeTrue()
        ->and(json_encode(array_map(static fn (LogRecord $record): array => $record->context, $handler->getRecords())))->not->toContain('a-secret-value');
});

it('logs denials as warnings with identifiers only', function (): void {
    $handler = captureLog();
    config()->set('laranail.settings.audit.sinks', ['null']);

    expect(fn () => settings()->as(user())->set('site.name', 'a-secret-value'))->toThrow(AccessDenied::class);

    $record = $handler->getRecords()[0];

    expect($record->level->getName())->toBe('WARNING')
        ->and($record->message)->toBe('laranail/settings refused [update]: access_denied')
        ->and($record->context['store'])->toBe('settings')
        ->and($record->context['key'])->toBe('site.name')
        ->and($record->context['actor']['id'])->toBe('1')
        ->and(json_encode($record->context))->not->toContain('a-secret-value');
});

it('writes nothing when the log channel is switched off', function (): void {
    $handler = captureLog();
    config()->set('laranail.settings.logging.channel', false);

    settings()->set('site.name', 'Acme');
    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(AccessDenied::class);

    expect($handler->getRecords())->toBe([]);
});

it('drops messages below logging.level', function (): void {
    $handler = captureLog();
    config()->set('laranail.settings.logging.level', 'warning');

    settings()->set('site.name', 'Acme');

    expect($handler->getRecords())->toBe([]);

    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(AccessDenied::class);

    expect($handler->getRecords())->toHaveCount(1);
});

it('rejects an unknown log level', function (): void {
    config()->set('laranail.settings.logging.level', 'loud');

    expect(fn () => app(OperationalLogger::class))->toThrow(InvalidConfiguration::class, 'logging.level: unknown level [loud]');
});

it('ignores a non-PSR level passed to the logger', function (): void {
    $sink = new class extends NullLogger
    {
        public int $calls = 0;

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->calls++;
        }
    };

    $logger = new OperationalLogger($sink, 'debug');
    $logger->log('verbose', 'ignored');
    $logger->log(7, 'ignored');
    $logger->debug('kept');

    expect($sink->calls)->toBe(1);
});

it('accepts null and custom audit sinks and rejects an unknown one', function (): void {
    config()->set('laranail.settings.audit.sinks', ['null', CountingSink::class]);
    CountingSink::$count = 0;

    settings()->set('site.name', 'Acme');

    expect(CountingSink::$count)->toBe(1);

    config()->set('laranail.settings.audit.sinks', ['database']);
    app()->forgetScopedInstances();

    expect(fn () => settings()->set('site.name', 'Acme'))->toThrow(InvalidConfiguration::class, 'laranail.settings.audit.sinks.0');
});

it('audits runtime configuration changes', function (): void {
    $sink = auditInMemory();

    settings()->configuration()->requireActor();

    expect(entriesFor($sink, AuditAction::Configure))->not->toBeEmpty()
        ->and(entriesFor($sink, AuditAction::Configure)[0]->meta['change'])->toBe('audit sink added');
});

it('records an actor for each audited ability through the fake, with assertions that can fail', function (): void {
    $fake = Settings::fake();
    allowGates(Ability::Update);

    $fake->as(user())->set('site.name', 'Acme');

    expect(fn () => $fake->as(user())->forget('site.name'))->toThrow(AccessDenied::class);

    $fake->assertAudited(AuditAction::Update, AuditOutcome::Allowed, 'site.name');
    $fake->assertDenied('site.name', Ability::Forget);
    $fake->assertDenied('site.name');

    expect(fn () => $fake->assertAudited(AuditAction::Lock))->toThrow(AssertionFailedError::class, 'No [lock] audit entry was recorded.')
        ->and(fn () => $fake->assertAudited(AuditAction::Update, AuditOutcome::Denied, 'site.name'))->toThrow(AssertionFailedError::class, 'No [update] denied audit entry for [site.name]')
        ->and(fn () => $fake->assertDenied('site.name', Ability::Update))->toThrow(AssertionFailedError::class, 'No denial of [update] was recorded for [site.name].')
        ->and(fn () => $fake->assertDenied('site.other'))->toThrow(AssertionFailedError::class);
});

final class CountingSink implements AuditSink
{
    public static int $count = 0;

    public function record(AuditEntry $entry): void
    {
        self::$count++;
    }
}
