<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\CacheTarget;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;

/*
 * The cache service on its own, over an in-memory cache store: the database side is
 * a closure that counts how often, and for which scopes, it is asked.
 */

function cacheTarget(string $connection = 'mysql', string $database = 'app'): CacheTarget
{
    return new CacheTarget($connection, $database, new StoreName('settings'), 'settings');
}

/**
 * A loader returning one record per requested scope, recording each call.
 *
 * @param list<list<string>> $calls
 */
function countingLoader(array &$calls): Closure
{
    return static function (array $scopes) use (&$calls): RecordSet {
        $calls[] = array_map(static fn (Scope $scope): string => $scope->value, $scopes);

        return new RecordSet(array_map(static fn (Scope $scope): Record => new Record($scope, new Key('a'), Locale::none(), '"' . $scope->value . '"'), $scopes));
    };
}

/** A cache store whose every operation fails, as an unreachable Redis would. */
function brokenCache(): Repository
{
    return new Repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            throw new RuntimeException('cache down');
        }

        public function many(array $keys): array
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }

        public function putMany(array $values, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }

        public function forever($key, $value): bool
        {
            throw new RuntimeException('cache down');
        }
    });
}

it('asks the database once for every missing scope, then not at all', function (): void {
    $calls = [];
    $cache = new SettingsCache(new Repository(new ArrayStore));
    $owner = Scope::forOwner(new OwnerRef('user', '1'));

    $first = $cache->load(cacheTarget(), [Scope::global(), $owner, Scope::global()], countingLoader($calls));
    $again = $cache->load(cacheTarget(), [$owner, Scope::global()], countingLoader($calls));

    expect($calls)->toBe([['global', 'user:1']])
        ->and($first->count())->toBe(2)
        ->and($again->count())->toBe(2)
        ->and($cache->load(cacheTarget(), [], countingLoader($calls))->count())->toBe(0);
});

it('shares bags across requests through the cache store, not the request memory', function (): void {
    $calls = [];
    $store = new Repository(new ArrayStore);

    new SettingsCache($store)->load(cacheTarget(), [Scope::global()], countingLoader($calls));
    new SettingsCache($store)->load(cacheTarget(), [Scope::global()], countingLoader($calls));

    expect($calls)->toBe([['global']]);
});

it('invalidates a scope, a store or everything with one token each', function (): void {
    $calls = [];
    $store = new Repository(new ArrayStore);
    $owner = Scope::forOwner(new OwnerRef('user', '1'));
    $load = static function (SettingsCache $cache, array $scopes) use (&$calls): void {
        $cache->load(cacheTarget(), $scopes, countingLoader($calls));
    };
    $writer = new SettingsCache($store);
    $load($writer, [Scope::global(), $owner]);

    $writer->forgetScope(cacheTarget(), $owner);
    $load(new SettingsCache($store), [Scope::global(), $owner]);

    $writer->forgetStore(cacheTarget());
    $load(new SettingsCache($store), [Scope::global(), $owner]);
    // Another store's bags are not touched by a store reset.
    new SettingsCache($store)->load(new CacheTarget('mysql', 'app', new StoreName('teams'), 'teams'), [Scope::global()], countingLoader($calls));
    $writer->forgetStore(cacheTarget());
    new SettingsCache($store)->load(new CacheTarget('mysql', 'app', new StoreName('teams'), 'teams'), [Scope::global()], countingLoader($calls));

    $writer->forgetAll();
    new SettingsCache($store)->load(new CacheTarget('mysql', 'app', new StoreName('teams'), 'teams'), [Scope::global()], countingLoader($calls));

    expect($calls)->toBe([['global', 'user:1'], ['user:1'], ['global', 'user:1'], ['global'], ['global']]);
});

it('keys every component apart, including a split that a plain join would collide on', function (): void {
    $cache = new SettingsCache(new Repository(new ArrayStore));
    $keys = [
        $cache->bagKey(cacheTarget('a', 'b:c'), Scope::global()),
        $cache->bagKey(cacheTarget('a:b', 'c'), Scope::global()),
        $cache->bagKey(cacheTarget('a', 'bc'), Scope::global()),
        $cache->bagKey(cacheTarget('mysql', 'app'), Scope::global()),
        $cache->bagKey(cacheTarget('pgsql', 'app'), Scope::global()),
        $cache->bagKey(cacheTarget('mysql', 'other'), Scope::global()),
        $cache->bagKey(cacheTarget('mysql', 'app'), Scope::forOwner(new OwnerRef('user', '1'))),
    ];

    expect(array_unique($keys))->toHaveCount(count($keys))
        ->and($keys[0])->toStartWith('laranail-settings:')
        ->and($cache->bagKey(cacheTarget('mysql', 'app'), Scope::global()))->toBe($keys[3]);
});

/** An operational log that hands each warning's context and message to `$record`. */
function failureLog(Closure $record): OperationalLogger
{
    return new OperationalLogger(new class($record) extends AbstractLogger
    {
        public function __construct(private readonly Closure $record) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            ($this->record)($context, (string) $message);
        }
    });
}

it('keys the tenant into every bag', function (): void {
    $tenant = new class
    {
        public ?string $id = 'a';
    };
    $calls = [];
    $cache = new SettingsCache(new Repository(new ArrayStore), tenant: new ClosureTenantDiscriminator(static fn (): ?string => $tenant->id));

    $a = $cache->bagKey(cacheTarget(), Scope::global());
    $cache->load(cacheTarget(), [Scope::global()], countingLoader($calls));
    $tenant->id = 'b';
    $b = $cache->bagKey(cacheTarget(), Scope::global());
    $cache->load(cacheTarget(), [Scope::global()], countingLoader($calls));
    $tenant->id = null;

    expect($a)->not->toBe($b)
        ->and($cache->bagKey(cacheTarget(), Scope::global()))->not->toBe($a)
        ->and($calls)->toHaveCount(2);
});

it('uses the configured prefix and key lifetimes', function (): void {
    $store = new Repository(new ArrayStore);
    $calls = [];
    $cache = new SettingsCache($store, prefix: 'acme', ttl: 10, stale: 0);
    $cache->load(cacheTarget(), [Scope::global()], countingLoader($calls));
    $key = $cache->bagKey(cacheTarget(), Scope::global());

    expect($key)->toStartWith('acme:')
        ->and($store->get($key))->toBeArray();

    $this->travel(11)->seconds();

    expect($store->get($key))->toBeNull();
});

it('reads from the database and reports it when the cache fails', function (): void {
    $calls = [];
    $failures = [];
    $cache = new SettingsCache(brokenCache(), log: failureLog(static function (array $context, string $message) use (&$failures): void {
        $failures[] = $context['operation'] . ': ' . (str_contains($message, 'cache down') ? 'cache down' : $message);
    }));

    $records = $cache->load(cacheTarget(), [Scope::global()], countingLoader($calls));
    $cache->forgetScope(cacheTarget(), Scope::global());
    $cache->forgetAll();

    expect($records->count())->toBe(1)
        ->and($calls)->toBe([['global']])
        ->and($failures)->toBe(['read: cache down', 'invalidate scope: cache down', 'invalidate all: cache down']);
});

it('reports a failed write of fresh bags and still answers them', function (): void {
    $calls = [];
    $failures = [];
    $store = new Repository(new class extends ArrayStore
    {
        public function putMany(array $values, $seconds): bool
        {
            throw new RuntimeException('full');
        }
    });
    $cache = new SettingsCache($store, log: failureLog(static function (array $context) use (&$failures): void {
        $failures[] = $context['operation'];
    }));

    expect($cache->load(cacheTarget(), [Scope::global()], countingLoader($calls))->count())->toBe(1)
        ->and($failures)->toBe(['write']);

    // Silent without a reporter.
    expect(new SettingsCache(brokenCache())->load(cacheTarget(), [Scope::global()], countingLoader($calls))->count())->toBe(1);
});

it('lets the first of two concurrent readers create a missing token', function (): void {
    $store = new Repository(new class extends ArrayStore
    {
        /** Another reader created every token a moment earlier. */
        public function add(string $key, mixed $value, int $seconds): bool
        {
            $this->put($key, 'WINNER', $seconds);

            return false;
        }
    });
    $epoch = new SettingsCache($store);
    $calls = [];
    $epoch->load(cacheTarget(), [Scope::global()], countingLoader($calls));

    expect($store->get('laranail-settings:epoch'))->not->toBeNull();
});

it('refuses an empty prefix, a ttl under a second and a negative stale window', function (array $arguments): void {
    expect(fn (): SettingsCache => new SettingsCache(new Repository(new ArrayStore), ...$arguments))->toThrow(InvalidConfiguration::class, 'laranail.settings.cache');
})->with([
    'empty prefix'   => [['prefix' => '']],
    'zero ttl'       => [['ttl' => 0]],
    'negative stale' => [['stale' => -1]],
]);

it('refuses a cache store that does not exist or is misnamed', function (mixed $store): void {
    config()->set('laranail.settings.cache.store', $store);
    app()->forgetScopedInstances();

    expect(fn () => app(SettingsCache::class))->toThrow(InvalidConfiguration::class, 'laranail.settings.cache.store');
})->with([
    'unknown' => ['nowhere'],
    'empty'   => [''],
    'number'  => [5],
]);

it('builds on the configured store, prefix and windows', function (): void {
    config()->set('laranail.settings.cache', ['enabled' => true, 'store' => 'array', 'prefix' => 'acme-settings', 'ttl' => 60, 'stale' => 5]);
    app()->forgetScopedInstances();
    $calls = [];
    $cache = app(SettingsCache::class);
    $cache->load(cacheTarget(), [Scope::global()], countingLoader($calls));

    expect($cache->bagKey(cacheTarget(), Scope::global()))->toStartWith('acme-settings:');
});

it('refuses a cache store that is not a Laravel cache repository', function (): void {
    app()->instance(CacheFactory::class, new class implements CacheFactory
    {
        public function store($name = null): CacheContract
        {
            return Mockery::mock(CacheContract::class);
        }
    });
    app()->forgetScopedInstances();

    expect(fn () => app(SettingsCache::class))->toThrow(InvalidConfiguration::class, 'is not a Laravel cache repository');
});

it('reports a failing cache to the operational log', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{string, string, array<array-key, mixed>}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = [(string) $level, (string) $message, $context];
        }
    };
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    config()->set('laranail.settings.cache.store', 'broken');
    Cache::extend('broken', static fn (): Repository => brokenCache());
    app()->forgetScopedInstances();
    settings()->configuration()->logTo($logger);
    $calls = [];

    app(SettingsCache::class)->load(cacheTarget(), [Scope::global()], countingLoader($calls));
    $failure = array_values(array_filter($logger->records, static fn (array $record): bool => str_contains($record[1], 'settings cache failed')));

    expect($calls)->toBe([['global']])
        ->and($failure)->toHaveCount(1)
        ->and($failure[0][0])->toBe('warning')
        ->and($failure[0][1])->toContain('(read): cache down')
        ->and($failure[0][2])->toBe(['store' => null, 'key' => null, 'scope' => null, 'actor' => null, 'channel' => null, 'operation' => 'read', 'exception' => RuntimeException::class]);
});
