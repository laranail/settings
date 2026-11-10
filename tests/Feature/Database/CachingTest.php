<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Settings\Data\Key;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Data\CacheTarget;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Settings\Drivers\CachingDriver;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Tests\Fixtures\SettingsOwner;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;

/*
 * Phase 3 (design §7.5, §11): the cache holds raw per-scope bags, keyed by connection, database,
 * tenant, store and scope, invalidated by generation tokens on every write path.
 *
 * "A new request" is simulated with forgetScopedInstances(): every scoped service (the registry,
 * the resolver, the cache's in-request memory) is rebuilt, while the cache store itself, a
 * singleton, keeps its entries, exactly as a second request or a second node would see it.
 */

/** Start a new request: scoped services rebuilt, the cache store kept. */
function nextRequest(): void
{
    app()->forgetScopedInstances();
}

/** Run a callback and return how many queries it sent to the default connection. */
function queriesDuring(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** The cached default store's database layer. */
function cachedLayer(): CachingDriver
{
    $driver = app(StoreRegistry::class)->default()->layers[0]->driver;
    assert($driver instanceof CachingDriver);

    return $driver;
}

function defaultTarget(): CacheTarget
{
    $database = cachedLayer()->database();
    $store = new StoreName('settings');

    return new CacheTarget($database->connectionName(), (string) $database->connection()->getDatabaseName(), $store, $database->tableFor($store));
}

beforeEach(function (): void {
    useDatabase([
        'stores.settings.owners'   => ['owner'],
        'stores.settings.inherits' => true,
        'stores.teams'             => ['layers' => ['database'], 'owners' => ['team']],
    ], stores: ['teams']);

    Schema::create('owners', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('team_id')->nullable();
    });

    Relation::morphMap(['owner' => SettingsOwner::class], false);
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('caches each database layer behind the settings cache, and only database layers', function (): void {
    $layers = app(StoreRegistry::class)->default()->layers;

    expect($layers[0]->driver)->toBeInstanceOf(CachingDriver::class)
        ->and($layers[1]->driver)->toBeInstanceOf(JsonDriver::class)
        ->and(app(StoreRegistry::class)->default()->isCached())->toBeTrue();
});

it('reads a cold chain with one query per distinct store, and a warm one with none', function (): void {
    $owner = SettingsOwner::query()->create(['team_id' => 7]);
    settings()->for(new OwnerRef('team', 7))->set('ui.density', 'compact');
    settings()->set('ui.theme', 'light');
    nextRequest();

    // owner → team (teams store) → global (settings store): two distinct stores.
    $cold = queriesDuring(static function () use ($owner): void {
        expect($owner->settings()->get('ui.density'))->toBe('compact');
    });
    $sameRequest = queriesDuring(static function () use ($owner): void {
        expect($owner->settings()->get('ui.theme'))->toBe('light');
    });
    nextRequest();
    $warm = queriesDuring(static function () use ($owner): void {
        expect($owner->settings()->get('ui.density'))->toBe('compact')
            ->and($owner->settings()->get('ui.theme'))->toBe('light')
            ->and($owner->settings()->get('ui.missing'))->toBeNull();
    });

    expect($cold)->toBe(2)
        ->and($sameRequest)->toBe(0)
        ->and($warm)->toBe(0);
});

it('caches a miss as an empty bag', function (): void {
    expect(settings()->get('nothing.here'))->toBeNull();
    nextRequest();

    expect(queriesDuring(static function (): void {
        expect(settings()->get('nothing.here'))->toBeNull();
    }))->toBe(0);
});

it('makes every write path visible to the next request', function (string $path): void {
    $owner = SettingsOwner::query()->create();
    $owner->settings()->set('ui.theme', 'dark');
    settings()->set('site.name', 'Acme');
    expect($owner->settings()->get('ui.theme'))->toBe('dark')
        ->and(settings()->get('site.name'))->toBe('Acme');
    nextRequest();
    // Both bags are now cached; every path below must invalidate the one it changes.
    expect($owner->settings()->get('ui.theme'))->toBe('dark');
    nextRequest();

    match ($path) {
        'set'     => $owner->settings()->set('ui.theme', 'light'),
        'forget'  => $owner->settings()->forget('ui.theme'),
        'lock'    => $owner->settings()->lock('ui.theme'),
        'unlock'  => [$owner->settings()->lock('ui.theme'), nextRequest(), $owner->settings()->get('ui.theme'), $owner->settings()->unlock('ui.theme')],
        'flush'   => $owner->settings()->flush(),
        'deleted' => $owner->delete(),
    };
    nextRequest();

    $handle = $owner->settings();

    match ($path) {
        'set'             => expect($handle->get('ui.theme'))->toBe('light'),
        'forget', 'flush' => expect($handle->get('ui.theme'))->toBeNull(),
        'deleted'         => expect(settings()->for(new OwnerRef('owner', $owner->id))->get('ui.theme'))->toBeNull(),
        'lock'            => expect(cachedLayer()->load(new StoreName('settings'), [Scope::forOwner(new OwnerRef('owner', $owner->id))])->all()[0]->locked)->toBeTrue(),
        'unlock'          => expect(cachedLayer()->load(new StoreName('settings'), [Scope::forOwner(new OwnerRef('owner', $owner->id))])->all()[0]->locked)->toBeFalse(),
    };
})->with(['set', 'forget', 'lock', 'unlock', 'flush', 'deleted']);

it('invalidates the store when values are promoted into it', function (): void {
    expect(settings()->get('site.name'))->toBeNull();
    $runtime = app(StoreRegistry::class)->default()->layers[1]->driver;
    assert($runtime instanceof JsonDriver);
    $runtime->put(new StoreName('settings'), new Record(Scope::global(), new Key('site.name'), Locale::none(), '"Acme"'));
    nextRequest();

    settings()->promote();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->explain('site.name')->lookup->layer())->toBe('database');
});

it('keeps a write made past the API invisible until the cache is cleared', function (): void {
    settings()->set('site.name', 'Acme');
    expect(settings()->get('site.name'))->toBe('Acme');
    DB::table('settings')->update(['value' => '"Changed"']);
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Acme');

    settings()->clearCache();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Changed');
});

it('reads its own uncommitted write inside a transaction and caches nothing a rollback undoes', function (): void {
    settings()->set('site.name', 'Acme');
    expect(settings()->get('site.name'))->toBe('Acme');

    DB::beginTransaction();
    settings()->set('site.name', 'Inside');
    expect(settings()->get('site.name'))->toBe('Inside');
    DB::rollBack();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Acme');

    DB::transaction(static fn () => settings()->set('site.name', 'Committed'));
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Committed');
});

it('keeps two connections apart even where every other key component is equal (G-37)', function (): void {
    // A second in-memory SQLite connection: same database name (`:memory:`), store, table and
    // scope as the default one under SQLite, so only the connection name tells them apart.
    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $default = config('database.default');
    config()->set('database.default', 'other');
    useDatabase(['stores.settings.owners' => ['owner']]);
    settings()->set('site.name', 'Other');

    config()->set('database.default', $default);
    app()->forgetInstance(DriverFactory::class);
    nextRequest();
    settings()->set('site.name', 'Default');
    expect(settings()->get('site.name'))->toBe('Default');

    // The same store's layer, pointed at the other connection.
    config()->set('laranail.settings.layers.database.connection', 'other');
    app()->forgetInstance(DriverFactory::class);
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Other');
});

it('never answers one tenant from another tenant\'s bag', function (): void {
    useDatabase(['tenancy.mode' => 'column']);
    $tenant = new class
    {
        public ?string $id = 'a';
    };
    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): ?string => $tenant->id));

    settings()->set('site.name', 'Tenant A');
    $tenant->id = 'b';
    settings()->set('site.name', 'Tenant B');
    $tenant->id = 'a';
    expect(settings()->get('site.name'))->toBe('Tenant A');
    $tenant->id = 'b';
    expect(settings()->get('site.name'))->toBe('Tenant B');

    // Once cached, the database is not asked again, and still neither tenant sees the other.
    $queries = queriesDuring(static function () use ($tenant): void {
        $tenant->id = 'a';
        expect(settings()->get('site.name'))->toBe('Tenant A');
        $tenant->id = 'b';
        expect(settings()->get('site.name'))->toBe('Tenant B');
    });

    expect($queries)->toBe(0);
});

it('serves a stale bag while it refreshes, and a refresh can never restore an invalidated value', function (): void {
    settings()->set('site.name', 'Acme');
    expect(settings()->get('site.name'))->toBe('Acme');
    DB::table('settings')->update(['value' => '"Changed directly"']);

    // Past the fresh window (ttl 3600), inside the stale one (300): the stale bag is answered and
    // a refresh is deferred.
    $this->travel(3700)->seconds();
    nextRequest();
    $deferred = app(DeferredCallbackCollection::class);
    $before = $deferred->count();

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and($deferred->count())->toBe($before + 1);

    // A write lands before the deferred refresh runs; the refresh then writes under the old
    // generation, where no reader looks any more.
    settings()->set('site.name', 'Written');
    $deferred->invoke();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Written');
});

it('refreshes a stale bag in the background when nothing was written', function (): void {
    settings()->set('site.name', 'Acme');
    expect(settings()->get('site.name'))->toBe('Acme');
    DB::table('settings')->update(['value' => '"Changed directly"']);
    $this->travel(3700)->seconds();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Acme');

    app(DeferredCallbackCollection::class)->invoke();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Changed directly');
});

it('ignores a bag written late under a generation a write has replaced', function (): void {
    settings()->set('site.name', 'Acme');
    $cache = app(SettingsCache::class);
    $target = defaultTarget();
    $before = $cache->bagKey($target, Scope::global());

    $cache->forgetScope($target, Scope::global());

    expect($cache->bagKey($target, Scope::global()))->not->toBe($before);

    // A slow reader that computed the old bag stores it after the write.
    Cache::store()->put($before, ['v' => 1, 'r' => []], 600);
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('expires a bag after its fresh and stale windows', function (): void {
    settings()->set('site.name', 'Acme');
    expect(settings()->get('site.name'))->toBe('Acme');
    DB::table('settings')->update(['value' => '"Changed directly"']);
    $this->travel(3901)->seconds();
    nextRequest();

    expect(settings()->get('site.name'))->toBe('Changed directly');
});

it('never caches a database layer that bootstrap mode is skipping', function (): void {
    config()->set('laranail.settings.stores.settings.bootstrap', true);
    config()->set('laranail.settings.bootstrap', true);
    config()->set('database.connections.unmigrated', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('laranail.settings.layers.database.connection', 'unmigrated');
    app()->forgetInstance(DriverFactory::class);
    nextRequest();

    expect(settings()->get('site.name'))->toBeNull()
        ->and(cachedLayer()->isActive())->toBeFalse()
        ->and(cachedLayer()->isWritable())->toBeFalse()
        ->and(cachedLayer()->uncached()->isActive())->toBeFalse()
        ->and(app(StoreRegistry::class)->default()->warmCache([Scope::global()]))->toBe(0);
});

it('warms and clears per scope, per store and everywhere, gated by manage_stores and audited', function (): void {
    settings()->set('site.name', 'Acme');
    nextRequest();

    expect(settings()->warmCache())->toBe(1)
        ->and(queriesDuring(static fn (): mixed => settings()->get('site.name')))->toBe(0);

    DB::table('settings')->update(['value' => '"Changed"']);

    // Another owner's bag, then another store: the global bag of `settings` survives both.
    settings()->for(new OwnerRef('owner', 1))->clearCache();
    settings()->store('teams')->clearCache();
    nextRequest();
    expect(settings()->get('site.name'))->toBe('Acme');

    settings()->clearCache();
    nextRequest();
    expect(settings()->get('site.name'))->toBe('Changed');

    // An actor needs manage_stores (step-up verification switched off here).
    config()->set('laranail.settings.verification.driver', 'none');
    nextRequest();
    $sink = auditInMemory();

    expect(fn () => settings()->as(user())->clearCache())->toThrow(AccessDenied::class)
        ->and(fn (): int => settings()->as(user())->warmCache())->toThrow(AccessDenied::class);

    allowGates(Ability::ManageStores);
    settings()->as(user())->clearCache();
    expect(settings()->as(user())->warmCache())->toBe(1);

    $allowed = array_values(array_filter($sink->entries(), static fn (AuditEntry $entry): bool => $entry->outcome === AuditOutcome::Allowed && $entry->action === AuditAction::ManageStores));
    $denied = array_values(array_filter($sink->entries(), static fn (AuditEntry $entry): bool => $entry->outcome === AuditOutcome::Denied));

    expect(array_map(static fn (AuditEntry $entry): mixed => $entry->meta['operation'] ?? null, $allowed))->toBe(['cache.clear', 'cache.warm'])
        ->and(array_map(static fn (AuditEntry $entry): AuditAction => $entry->action, $allowed))->toBe([AuditAction::ManageStores, AuditAction::ManageStores])
        ->and($allowed[0]->meta['target'] ?? null)->toBe('store')
        ->and($allowed[1]->meta['records'] ?? null)->toBe(1)
        ->and($denied)->toHaveCount(2);
});

it('warms and clears from the console', function (): void {
    settings()->set('site.name', 'Acme');
    nextRequest();

    $this->artisan('laranail::settings.warm')
        ->expectsTable(['Store', 'Records cached'], [['settings', 1], ['teams', 0]])
        ->assertSuccessful();
    expect(queriesDuring(static fn (): mixed => settings()->get('site.name')))->toBe(0);

    DB::table('settings')->update(['value' => '"Changed"']);
    $this->artisan('laranail::settings.clear-cache', ['--store' => ['teams']])
        ->expectsOutputToContain('Settings cache reset (teams).')
        ->assertSuccessful();
    nextRequest();
    expect(settings()->get('site.name'))->toBe('Acme');

    $this->artisan('laranail::settings.clear-cache')
        ->expectsOutputToContain('Settings cache reset (every store).')
        ->assertSuccessful();
    nextRequest();
    expect(settings()->get('site.name'))->toBe('Changed');

    $this->artisan('laranail::settings.warm', ['--store' => ['teams']])->assertSuccessful();
    $this->artisan('laranail::settings.warm', ['--store' => ['missing']])->assertFailed();
    $this->artisan('laranail::settings.clear-cache', ['--store' => ['missing']])->assertFailed();
});

it('reads straight from the database when caching is off', function (): void {
    config()->set('laranail.settings.cache.enabled', false);
    nextRequest();
    settings()->set('site.name', 'Acme');
    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(app(StoreRegistry::class)->default()->layers[0]->driver)->not->toBeInstanceOf(CachingDriver::class)
        ->and(app(StoreRegistry::class)->default()->isCached())->toBeFalse()
        ->and(settings()->warmCache())->toBe(0);

    settings()->clearCache();

    $this->artisan('laranail::settings.warm')->expectsOutputToContain('nothing to warm')->assertSuccessful();
    $this->artisan('laranail::settings.clear-cache')->expectsOutputToContain('Caching is off')->assertSuccessful();

    DB::table('settings')->update(['value' => '"Changed"']);

    expect(settings()->get('site.name'))->toBe('Changed');
});

it('loads every scope of a read in one query and caches each bag on its own', function (): void {
    $records = app(StoreRegistry::class)->default()->layers[0]->driver->load(new StoreName('settings'), []);
    expect($records)->toBeInstanceOf(RecordSet::class)->and($records->count())->toBe(0);

    settings()->set('site.name', 'Acme');
    settings()->for(new OwnerRef('owner', 1))->set('ui.theme', 'dark');
    nextRequest();
    $scopes = [Scope::global(), Scope::forOwner(new OwnerRef('owner', 1)), Scope::forOwner(new OwnerRef('owner', 2)), Scope::global()];

    $cold = queriesDuring(static function () use ($scopes): void {
        expect(cachedLayer()->load(new StoreName('settings'), $scopes)->count())->toBe(2);
    });
    nextRequest();
    $warm = queriesDuring(static function (): void {
        expect(cachedLayer()->load(new StoreName('settings'), [Scope::forOwner(new OwnerRef('owner', 2))])->count())->toBe(0);
    });

    expect($cold)->toBe(1)->and($warm)->toBe(0);
});
