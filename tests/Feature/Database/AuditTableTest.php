<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;

beforeEach(function (): void {
    useDatabase();
});

it('writes the audit row inside the write\'s transaction, without the value', function (): void {
    $levels = new class implements AuditSink
    {
        /** @var list<int> */
        public array $levels = [];

        public function record(AuditEntry $entry): void
        {
            if ($entry->action === AuditAction::Update) {
                $this->levels[] = DB::connection()->transactionLevel();
            }
        }
    };
    settings()->configuration()->auditTo($levels);
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();

    $secret = TestCredentials::secret('audit');
    settings()->because('INC-1')->set('mail.password', $secret);

    $row = DB::table('settings_audit')->where('action', 'update')->first();

    expect($levels->levels)->toBe([1])
        ->and($row?->action)->toBe('update')
        ->and($row?->outcome)->toBe('allowed')
        ->and($row?->key)->toBe('mail.password')
        ->and($row?->reason)->toBe('INC-1')
        ->and((int) $row?->version_to)->toBe(1)
        ->and($row?->actor_type)->toBe('system')
        ->and(json_encode($row))->not->toContain($secret)
        ->and(Schema::hasColumn('settings_audit', 'value'))->toBeFalse();
});

it('rolls the write back when its audit entry cannot be written', function (): void {
    settings()->configuration()->auditTo(new class implements AuditSink
    {
        public function record(AuditEntry $entry): void
        {
            if ($entry->action === AuditAction::Update && $entry->outcome === AuditOutcome::Allowed) {
                throw new DriverFailure('audit storage is full');
            }
        }
    });

    expect(fn () => settings()->set('site.name', 'Acme'))->toThrow(DriverFailure::class, 'audit storage is full')
        ->and(DB::table('settings')->count())->toBe(0)
        ->and(DB::table('settings_audit')->where('action', 'update')->where('outcome', 'allowed')->count())->toBe(0)
        ->and(DB::table('settings_audit')->where('action', 'update')->where('outcome', 'failed')->count())->toBe(1);
});

it('audits a denial outside any transaction, so it survives the refusal', function (): void {
    expect(fn () => settings()->as(user())->set('site.name', 'Acme'))->toThrow(AccessDenied::class);

    $row = DB::table('settings_audit')->first();

    expect($row?->outcome)->toBe('denied')
        ->and($row?->actor_alias)->not->toBeNull()
        ->and(json_decode((string) $row?->meta, true)['refusal'] ?? null)->toBe('access_denied')
        ->and(DB::table('settings')->count())->toBe(0);
});

it('falls back to the log for a store without a database layer', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['runtime']);
    app()->forgetScopedInstances();

    settings()->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(DB::table('settings_audit')->count())->toBe(0);
});

it('refuses the database sink with no database layer to write to', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['runtime']);
    config()->set('laranail.settings.audit.sinks', ['database']);
    app()->forgetScopedInstances();

    expect(fn () => settings()->set('site.name', 'Acme'))->toThrow(DriverFailure::class, 'no active database layer');
});

it('prunes audit rows older than the retention period', function (): void {
    $this->travelTo(now()->subDays(400));
    settings()->set('site.name', 'Old');
    $this->travelBack();
    settings()->set('site.name', 'New');

    $this->artisan('laranail::settings.prune-audit')->expectsOutputToContain('Removed 1 audit entry older than 365 day(s).')->assertSuccessful();
    $this->artisan('laranail::settings.prune-audit', ['--days' => '0'])->assertFailed();
    $this->artisan('laranail::settings.prune-audit', ['--days' => 'soon'])->assertFailed();

    // The kept row, plus the prune's own entry: the trail records who shortened it.
    expect(DB::table('settings_audit')->count())->toBe(2)
        ->and(DB::table('settings_audit')->where('action', 'prune')->value('actor_type'))->toBe('system');
});
