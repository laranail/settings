<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Filesystem\FilesystemAdapter;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Channel;
use League\Flysystem\Filesystem as Flysystem;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Events\StorePromoted;
use Simtabi\Laranail\Settings\Events\StorePromoting;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

const RUNTIME_FILE = 'laranail/settings/settings.json';

/** A runtime file holding values written before the database existed. */
function writeRuntimeFile(string $name = 'Acme'): void
{
    Storage::disk('local')->put(RUNTIME_FILE, (string) json_encode(['records' => [
        ['scope' => 'global', 'key' => 'site.name', 'locale' => '', 'payload' => json_encode($name), 'version' => 1],
    ]]));
}

/** Set the default store's promotion mode, as an operator would in config. */
function promoteMode(string $mode): void
{
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);
    config()->set('laranail.settings.stores.settings.promote', $mode);
    app()->forgetScopedInstances();
}

/** A logger that keeps what it is given. */
function promotionLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{string, string, array<array-key, mixed>}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = [(string) $level, (string) $message, $context];
        }
    };
}

/** Count existence checks on the `local` disk. */
function countingDisk(): object
{
    $root = Storage::disk('local')->path('');
    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public int $checks = 0;

        public function fileExists(string $location): bool
        {
            $this->checks++;

            return parent::fileExists($location);
        }
    };

    Storage::set('local', new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $root]));

    return $adapter;
}

it('waits for the database, then promotes exactly once, straight after the migrations', function (): void {
    Event::fake([StorePromoted::class]);
    writeRuntimeFile();
    promoteMode('auto');

    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeTrue();
    Event::assertNotDispatched(StorePromoted::class);

    // migrate fires MigrationsEnded, which promotes in the console once readiness is flushed.
    useDatabase(['stores.settings.promote' => 'auto']);

    Event::assertDispatchedTimes(StorePromoted::class, 1);
    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse()
        ->and(DB::table('settings')->where('key', 'site.name')->value('value'))->toBe('"Acme"')
        ->and(settings()->lookup('site.name')->layer())->toBe('database');

    app()->terminate();

    Event::assertDispatchedTimes(StorePromoted::class, 1);
});

it('promotes after the request ends and after a queued job, as the system', function (): void {
    useDatabase();
    $audit = auditInMemory();
    writeRuntimeFile();
    settings()->configuration()->promote('settings', 'auto');

    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse()
        ->and(settings()->get('site.name'))->toBe('Acme');

    $entry = array_values(array_filter($audit->entries(), static fn (AuditEntry $entry): bool => $entry->action === AuditAction::Promote))[0] ?? null;
    expect($entry?->actorType)->toBe(ActorType::System)
        ->and($entry?->reason)->toBe('laranail::settings.auto-promote')
        // The test runs in the console; a web request records `php`.
        ->and($entry?->channel)->toBe(Channel::Console);

    writeRuntimeFile('Later');
    DB::table('settings')->delete();
    event(new JobProcessed('sync', Mockery::mock(Job::class)));

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse()
        ->and(DB::table('settings')->value('value'))->toBe('"Later"');

    $channels = array_map(static fn (AuditEntry $entry): Channel => $entry->channel, array_values(array_filter($audit->entries(), static fn (AuditEntry $entry): bool => $entry->action === AuditAction::Promote)));
    expect($channels)->toBe([Channel::Console, Channel::Queue]);
});

it('skips quietly while another process holds the promotion lock', function (): void {
    useDatabase();
    $logger = promotionLogger();
    writeRuntimeFile();
    promoteMode('auto');
    settings()->configuration()->logTo($logger);

    $lock = Cache::lock('laranail-settings:promote:settings', 60);
    $lock->get();
    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeTrue()
        ->and(array_filter($logger->records, static fn (array $record): bool => $record[0] === 'warning'))->toBe([]);

    $lock->release();
    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse();
});

it('logs a failure, keeps reading the file, and succeeds on a later run', function (): void {
    useDatabase();
    $logger = promotionLogger();
    writeRuntimeFile();
    promoteMode('auto');
    settings()->configuration()->logTo($logger);
    $disk = new ArrayObject(['full' => true]);
    Event::listen(StorePromoting::class, static function () use ($disk): void {
        if ($disk['full'] === true) {
            throw new RuntimeException('disk full');
        }
    });

    app()->terminate();

    $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => $record[0] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0][1])->toContain('could not promote store [settings]')
        ->and(array_slice(array_keys($warnings[0][2]), 0, 5))->toBe(['store', 'key', 'scope', 'actor', 'channel'])
        ->and($warnings[0][2]['store'])->toBe('settings')
        ->and($warnings[0][2]['message'])->toBe('disk full')
        ->and(Storage::disk('local')->exists(RUNTIME_FILE))->toBeTrue()
        ->and(settings()->lookup('site.name')->layer())->toBe('runtime');

    $disk['full'] = false;
    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse()
        ->and(settings()->lookup('site.name')->layer())->toBe('database');
});

it('runs no query once promoted, or when there is no file, and checks the file once', function (): void {
    useDatabase();
    writeRuntimeFile();
    promoteMode('auto');
    app()->terminate();
    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse();

    $disk = countingDisk();
    app()->forgetScopedInstances();
    // A new request: nothing remembers that the database was ready.
    app(SchemaReadinessInterface::class)->flush();
    DB::enableQueryLog();
    DB::flushQueryLog();

    app()->terminate();

    expect(DB::getQueryLog())->toBe([])
        ->and($disk->checks)->toBe(1);
});

it('never promotes on its own in manual mode, the default', function (): void {
    useDatabase();
    writeRuntimeFile();
    DB::enableQueryLog();
    DB::flushQueryLog();

    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeTrue()
        ->and(DB::getQueryLog())->toBe([]);

    // The global override turns every store to auto, and to manual again.
    config()->set('laranail.settings.promote', 'manual');
    promoteMode('auto');
    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeTrue();

    config()->set('laranail.settings.promote', 'auto');
    promoteMode('manual');
    app()->terminate();

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse();
});

it('rejects a promotion mode it does not know', function (): void {
    promoteMode('sometimes');

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(InvalidConfiguration::class, 'laranail.settings.stores.settings.promote: use [manual] or [auto].')
        ->and(fn (): mixed => settings()->configuration()->promote('settings', 'later'))->toThrow(InvalidConfiguration::class, 'use [manual] or [auto]');

    config()->set('laranail.settings.stores.settings.promote', 'manual');
    config()->set('laranail.settings.promote', 'eventually');
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(InvalidConfiguration::class, 'laranail.settings.promote: use [manual] or [auto].');
});

it('promotes now from promoteWhenReady() when the database is ready', function (): void {
    useDatabase();
    writeRuntimeFile();

    $report = settings()->store('settings')->asSystem('installer')->promoteWhenReady();

    expect($report)->toBeInstanceOf(PromotionReport::class)
        ->and($report?->promoted)->toBe(['global|site.name|'])
        ->and(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse();
});

it('defers promoteWhenReady() until the migrations when the database is not ready', function (): void {
    // An installer: bootstrap mode, with the evidence of an install in progress.
    config()->set('laranail.settings.stores.settings.bootstrap', true);
    config()->set('laranail.settings.bootstrap', true);
    writeRuntimeFile();
    promoteMode('manual');

    expect(settings()->asSystem('installer')->promoteWhenReady())->toBeNull()
        ->and(settings()->configuration()->promotion('settings'))->toBe('auto')
        ->and(Storage::disk('local')->exists(RUNTIME_FILE))->toBeTrue();

    // An installer migrating in the same process: the runtime switch is still in force.
    Artisan::call('migrate', ['--path' => dirname(__DIR__, 3) . '/database/migrations', '--realpath' => true]);

    expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse()
        ->and(settings()->get('site.name'))->toBe('Acme');

    config()->set('laranail.settings.stores.settings.layers', ['runtime']);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->promoteWhenReady())->toThrow(StoreNotWritable::class, 'no database layer');
});

it('passes promoteWhenReady() through the fake', function (): void {
    expect(fn (): mixed => Settings::fake()->promoteWhenReady())->toThrow(StoreNotWritable::class, 'no database layer');
});

it('warns from doctor when an auto store still has its file while the database is ready', function (): void {
    useDatabase();
    $this->artisan('laranail::settings.doctor')->doesntExpectOutputToContain('settings: promotion')->assertSuccessful();

    writeRuntimeFile();
    config()->set('laranail.settings.stores.settings.promote', 'auto');
    app()->forgetScopedInstances();

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain('The runtime file has not been promoted although the database is ready')
        ->assertSuccessful();

    app()->terminate();
    app()->forgetScopedInstances();

    $this->artisan('laranail::settings.doctor')->expectsOutputToContain('No runtime file is waiting to be promoted.')->assertSuccessful();
});

it('logs a configuration it cannot build instead of throwing into the request', function (): void {
    config()->set('laranail.settings.promote', 'auto');
    config()->set('laranail.settings.stores.settings.layers', ['missing']);
    config()->set('laranail.settings.audit.sinks', ['null']);
    app()->forgetScopedInstances();
    $logger = promotionLogger();
    settings()->configuration()->logTo($logger);

    app()->terminate();

    $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => $record[0] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0][1])->toContain('could not promote store [*]')
        ->and($warnings[0][2]['exception'])->toBe(InvalidConfiguration::class);
});

/** A runtime file with a global value and two owners' values. */
function writeMixedRuntimeFile(): void
{
    Storage::disk('local')->put(RUNTIME_FILE, (string) json_encode(['records' => [
        ['scope' => 'global', 'key' => 'site.name', 'locale' => '', 'payload' => '"Acme"', 'version' => 1],
        ['scope' => 'user:1', 'key' => 'ui.theme', 'locale' => '', 'payload' => '"dark"', 'version' => 1],
        ['scope' => 'team:2', 'key' => 'ui.theme', 'locale' => '', 'payload' => '"light"', 'version' => 1],
    ]]));
}

/** @return list<string> the scopes left in the runtime file */
function runtimeScopes(): array
{
    $file = json_decode((string) Storage::disk('local')->get(RUNTIME_FILE), true);

    return array_column($file['records'], 'scope');
}

it('promotes only the kinds a store selects, and the rest keep answering from the file', function (array $promote, array $inDatabase, array $inFile): void {
    useDatabase(['stores.settings.owners' => ['user', 'team'], 'stores.settings.promote' => ['mode' => 'auto', ...$promote]]);
    writeMixedRuntimeFile();

    app()->terminate();

    expect(DB::table('settings')->orderBy('scope')->pluck('scope')->all())->toBe($inDatabase);

    if ($inFile === []) {
        expect(Storage::disk('local')->exists(RUNTIME_FILE))->toBeFalse();
    } else {
        expect(runtimeScopes())->toBe($inFile)
            ->and(settings()->for(new OwnerRef('team', 2))->lookup('ui.theme')->layer())->toBe(in_array('team:2', $inFile, true) ? 'runtime' : 'database')
            ->and(settings()->for(new OwnerRef('team', 2))->get('ui.theme'))->toBe('light');
    }

    // The archive keeps the whole original file.
    $archive = collect(Storage::disk('local')->files('laranail/settings'))->first(static fn (string $path): bool => str_contains($path, '.promoted-'));
    expect(count(json_decode((string) Storage::disk('local')->get((string) $archive), true)['records']))->toBe(3);

    // A remainder the selection leaves out is not promoted again, and costs no query.
    DB::enableQueryLog();
    DB::flushQueryLog();
    app()->terminate();

    expect(DB::getQueryLog())->toBe([])
        ->and(Storage::disk('local')->files('laranail/settings'))->toHaveCount($inFile === [] ? 1 : 2);
})->with([
    'settings only'   => [['include' => ['settings']], ['global'], ['user:1', 'team:2']],
    'owners only'     => [['include' => ['owners']], ['team:2', 'user:1'], ['global']],
    'everything'      => [['include' => ['*']], ['global', 'team:2', 'user:1'], []],
    'one owner alias' => [['include' => ['owners'], 'owners' => ['user']], ['user:1'], ['global', 'team:2']],
]);

it('takes the string shorthand as everything, and refuses kinds the runtime file cannot hold', function (): void {
    config()->set('laranail.settings.stores.settings.promote', 'auto');
    app()->forgetScopedInstances();

    expect(app(StoreRegistry::class)->default()->promotion->isEverything())->toBeTrue();

    config()->set('laranail.settings.stores.settings.promote', ['mode' => 'auto', 'include' => ['settings', 'categories']]);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('x'))->toThrow(InvalidConfiguration::class, 'laranail.settings.stores.settings.promote.include: [categories] cannot be promoted; the runtime file holds setting values only');

    config()->set('laranail.settings.stores.settings.promote', ['mode' => 'auto', 'owners' => 'user']);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('x'))->toThrow(InvalidConfiguration::class, 'promote.owners: a list of owner aliases');

    config()->set('laranail.settings.stores.settings.promote', ['mode' => 'auto', 'only' => ['settings']]);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('x'))->toThrow(InvalidConfiguration::class, 'unknown option [only]');
});

it('selects from the command and from promoteWhenReady()', function (): void {
    useDatabase(['stores.settings.owners' => ['user', 'team']]);
    writeMixedRuntimeFile();

    $this->artisan('laranail::settings.promote', ['--only' => 'owners', '--owner' => 'team'])
        ->expectsOutputToContain('Store [settings]: 1 promoted, 0 skipped.')
        ->expectsOutputToContain('2 value(s) not selected stay in the runtime file.')
        ->assertSuccessful();

    expect(runtimeScopes())->toBe(['global', 'user:1']);

    $this->artisan('laranail::settings.promote', ['--only' => 'categories'])->expectsOutputToContain('[categories] cannot be promoted')->assertFailed();

    $report = settings()->asSystem('installer')->promoteWhenReady(new PromotionSelection(settings: true, owners: false));

    expect($report?->promoted)->toBe(['global|site.name|'])
        ->and($report?->kept)->toBe(['user:1|ui.theme|'])
        ->and(runtimeScopes())->toBe(['user:1']);
});

it('decides "already has a row" from the stored rows, never from a stale cache', function (): void {
    useDatabase();
    // The cache remembers an empty global scope...
    expect(settings()->get('site.name'))->toBeNull();
    // ...and a row arrives behind its back.
    DB::table('settings')->insert(['id' => (string) Str::ulid(), 'uid' => (string) Str::ulid(), 'scope' => 'global', 'key' => 'site.name', 'group' => 'site', 'locale' => '', 'value' => '"Stored"', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    writeRuntimeFile('File');

    $report = settings()->promote();

    expect($report->skipped)->toBe(['global|site.name|' => 'exists'])
        ->and(DB::table('settings')->value('value'))->toBe('"Stored"');
});

it('lets fluent setters win over configuration, option by option', function (): void {
    config()->set('laranail.settings.stores.settings.promote', ['mode' => 'manual', 'include' => ['settings'], 'owners' => ['team']]);
    app()->forgetScopedInstances();
    $store = static fn () => app(StoreRegistry::class)->default();

    // Unset fluent values fall through to configuration.
    expect($store()->autoPromote)->toBeFalse()
        ->and($store()->promotion->settings)->toBeTrue()
        ->and($store()->promotion->owners)->toBeFalse();

    $builder = settings()->store('settings')->promotion();

    expect($builder->auto())->toBe($builder)
        ->and($store()->autoPromote)->toBeTrue()
        ->and($store()->promotion->owners)->toBeFalse()
        ->and($builder->only('owners'))->toBe($builder)
        ->and($store()->promotion->settings)->toBeFalse()
        ->and($store()->promotion->ownerAliases)->toBe(['team'])
        ->and($builder->forOwners('user', 'team')->forAllOwners())->toBe($builder)
        ->and($store()->promotion->ownerAliases)->toBeNull()
        ->and($builder->forOwners('user'))->toBe($builder)
        ->and($store()->promotion->ownerAliases)->toBe(['user'])
        ->and($builder->everything())->toBe($builder)
        ->and($store()->promotion->isEverything())->toBeTrue()
        ->and($builder->manual())->toBe($builder)
        ->and($store()->autoPromote)->toBeFalse();

    // The fluent mode beats the global override too.
    config()->set('laranail.settings.promote', 'auto');
    app()->forgetInstance(StoreRegistry::class);

    expect($store()->autoPromote)->toBeFalse()
        ->and(fn (): mixed => $builder->only('categories'))->toThrow(InvalidConfiguration::class, '[categories] cannot be promoted');
});

it('keeps fluent promotion settings to the request that set them', function (): void {
    settings()->store('settings')->promotion()->auto()->only('owners');

    expect(app(StoreRegistry::class)->default()->autoPromote)->toBeTrue();

    // What Octane does between requests.
    app()->forgetScopedInstances();

    expect(app(StoreRegistry::class)->default()->autoPromote)->toBeFalse()
        ->and(app(StoreRegistry::class)->default()->promotion->isEverything())->toBeTrue();
});

it('drives the fluent chain through the fake, and promoteWhenReady() uses the merged result', function (): void {
    Settings::fake()->store('settings')->promotion()->auto()->only('settings');

    expect(app(StoreRegistry::class)->default()->autoPromote)->toBeTrue()
        ->and(app(StoreRegistry::class)->default()->promotion->owners)->toBeFalse();

    useDatabase(['stores.settings.owners' => ['user', 'team']]);
    writeMixedRuntimeFile();
    settings()->store('settings')->promotion()->only('owners')->forOwners('team');

    $report = settings()->store('settings')->promoteWhenReady();

    expect($report?->promoted)->toBe(['team:2|ui.theme|'])
        ->and(runtimeScopes())->toBe(['global', 'user:1']);

    // The command's defaults read the same resolved options.
    $this->artisan('laranail::settings.promote')->expectsOutputToContain('0 promoted')->assertSuccessful();
});
