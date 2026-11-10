<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Events\LayerSkipped;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Events\StorePromoted;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\PromotionLocked;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/** A store in bootstrap mode whose database layer points at a database that does not exist yet. */
function bootstrapWithoutDatabase(bool $evidence): void
{
    config()->set('database.connections.unmigrated', ['driver' => 'sqlite', 'database' => sys_get_temp_dir() . '/laranail-missing-' . bin2hex(random_bytes(4)) . '/db.sqlite', 'prefix' => '']);
    config()->set('laranail.settings.layers.database.connection', 'unmigrated');
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);
    config()->set('laranail.settings.stores.settings.bootstrap', true);
    config()->set('laranail.settings.bootstrap', $evidence);
    app()->forgetInstance(DriverFactory::class);
    app()->forgetScopedInstances();
}

it('touches no database when the application boots and resolves settings', function (): void {
    bootstrapWithoutDatabase(evidence: false);
    DB::enableQueryLog();

    $settings = settings();

    expect($settings)->not->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('raises DriverUnavailable after install when the database is down: no fallback to files', function (): void {
    bootstrapWithoutDatabase(evidence: false);
    shipDefaults(['maintenance' => false]);
    app()->forgetInstance(DriverFactory::class);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('maintenance'))->toThrow(DriverUnavailable::class)
        ->and(fn () => settings()->set('site.name', 'x'))->toThrow(DriverUnavailable::class)
        ->and(Storage::disk('local')->exists('laranail/settings/settings.json'))->toBeFalse();
});

it('skips the database layer only while there is evidence of an install, and says so', function (): void {
    Event::fake([LayerSkipped::class]);
    bootstrapWithoutDatabase(evidence: true);

    settings()->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(Storage::disk('local')->exists('laranail/settings/settings.json'))->toBeTrue();

    Event::assertDispatchedTimes(LayerSkipped::class, 1);
    Event::assertDispatched(LayerSkipped::class, static fn (LayerSkipped $event): bool => $event->store === 'settings' && $event->layer === 'database');
});

it('switches from the runtime file to the database once migrated, and promotion carries the values over', function (): void {
    config()->set('laranail.settings.stores.settings.bootstrap', true);
    config()->set('laranail.settings.bootstrap', true);
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);
    app()->forgetScopedInstances();
    // Pre-database: no tables yet, so writes land in the runtime file.
    settings()->set('site.name', 'Acme');
    expect(Schema::hasTable('settings'))->toBeFalse();

    useDatabase(['stores.settings.bootstrap' => true, 'bootstrap' => true]);

    expect(settings()->lookup('site.name')->layer())->toBe('runtime');

    $report = settings()->promote();

    expect($report->promoted)->toBe(['global|site.name|'])
        ->and(DB::table('settings')->where('key', 'site.name')->exists())->toBeTrue()
        ->and(settings()->lookup('site.name')->layer())->toBe('database')
        ->and(Storage::disk('local')->exists('laranail/settings/settings.json'))->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $report->archive))->toBeTrue();
});

it('reads installer-headless as evidence and treats no answer as none', function (): void {
    expect(new InstallationEvidence(false)->holds())->toBeFalse()
        ->and(new InstallationEvidence(true)->holds())->toBeTrue()
        ->and(new InstallationEvidence(false, static fn (): bool => true)->holds())->toBeTrue()
        ->and(new InstallationEvidence(false, static fn (): bool => false)->holds())->toBeFalse();
});

it('promotes only runtime-written values, never shipped defaults', function (): void {
    shipDefaults(['site.name' => 'Shipped', 'site.motto' => 'Shipped motto']);
    app()->forgetScopedInstances();
    settings()->set('site.name', 'Written at install');

    useDatabase();
    $report = settings()->promote();

    expect($report->promoted)->toBe(['global|site.name|'])
        ->and(DB::table('settings')->pluck('key')->all())->toBe(['site.name'])
        ->and(settings()->get('site.motto'))->toBe('Shipped motto');
});

it('is idempotent and skips keys that already have a row unless forced', function (): void {
    settings()->set('site.name', 'File');

    useDatabase();
    settings()->set('site.name', 'Database');
    Storage::disk('local')->put('laranail/settings/settings.json', (string) json_encode(['records' => [
        ['scope' => 'global', 'key' => 'site.name', 'locale' => '', 'payload' => '"File"', 'version' => 1],
    ]]));

    $first = settings()->promote();

    expect($first->skipped)->toBe(['global|site.name|' => 'exists'])
        ->and(settings()->get('site.name'))->toBe('Database')
        ->and(settings()->promote()->promoted)->toBe([]);

    $replayed = settings()->promote(new PromotionRequest(force: true, from: (string) $first->archive));

    expect($replayed->promoted)->toBe(['global|site.name|'])
        ->and($replayed->archive)->toBeNull()
        ->and(settings()->get('site.name'))->toBe('File')
        ->and((int) DB::table('settings')->value('version'))->toBe(2);
});

it('skips keys a strict store no longer defines, and re-encrypts encrypted ones', function (): void {
    $registry = app(DefinitionRegistry::class);
    $registry->define('mail.password')->encrypted()->register();
    $secret = TestCredentials::secret('promotion');
    settings()->set('mail.password', $secret);
    settings()->set('old.key', 1);

    useDatabase(['stores.settings.strict' => true]);
    $report = settings()->promote();

    expect($report->promoted)->toBe(['global|mail.password|'])
        ->and($report->skipped)->toBe(['global|old.key|' => 'undefined'])
        ->and((bool) DB::table('settings')->value('is_encrypted'))->toBeTrue()
        ->and(DB::table('settings')->value('value'))->not->toContain($secret)
        ->and(settings()->get('mail.password'))->toBe($secret);
});

it('needs the promote ability, a database layer and the promotion lock', function (): void {
    useDatabase();

    expect(fn (): PromotionReport => settings()->as(user())->promote())->toThrow(AccessDenied::class, '[promote]');

    allowGates(Ability::Promote);
    expect(settings()->as(user())->promote()->promoted)->toBe([]);

    $lock = Cache::lock('laranail-settings:promote:settings', 60);
    $lock->get();

    expect(fn (): PromotionReport => settings()->promote())->toThrow(PromotionLocked::class);
    $lock->release();

    config()->set('laranail.settings.stores.settings.layers', ['runtime']);
    app()->forgetScopedInstances();

    expect(fn (): PromotionReport => settings()->promote())->toThrow(StoreNotWritable::class, 'no active database layer')
        ->and(fn (): PromotionRequest => new PromotionRequest(from: '../escape.json'))->toThrow(InvalidIdentifier::class);
});

it('promotes from the console and reports what it skipped', function (): void {
    Event::fake([StorePromoted::class]);
    settings()->set('site.name', 'File');
    useDatabase();
    settings()->set('site.motto', 'Db');
    Storage::disk('local')->put('laranail/settings/settings.json', (string) json_encode(['records' => [
        ['scope' => 'global', 'key' => 'site.name', 'locale' => '', 'payload' => '"File"', 'version' => 1],
        ['scope' => 'global', 'key' => 'site.motto', 'locale' => '', 'payload' => '"File"', 'version' => 1],
    ]]));

    $this->artisan('laranail::settings.promote')
        ->expectsOutputToContain('Store [settings]: 1 promoted, 1 skipped.')
        ->expectsOutputToContain('global|site.motto|')
        ->expectsOutputToContain('archived as')
        ->assertSuccessful();

    $this->artisan('laranail::settings.promote', ['store' => 'nope'])->assertFailed();

    Event::assertDispatched(StorePromoted::class, static fn (StorePromoted $event): bool => $event->promoted === 1 && $event->skipped === 1);
});
