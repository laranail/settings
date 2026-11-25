<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Closure;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\StoreMode;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Drivers\CachingDriver;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;

/**
 * A configured store: its layers in read order, the owner aliases it accepts, and its policies.
 */
final readonly class Store
{
    /**
     * @param list<Layer> $layers
     * @param list<string> $owners
     * @param list<string> $approvalKeys key globs whose writes become change requests
     * @param bool $autoPromote promote the runtime file once the database is ready (`promote: auto`)
     * @param ?string $ownerTable a dedicated store's owner table (`owner.table`), keyed by `$ownerKey`
     * @param PromotionSelection $promotion which runtime-file values automatic promotion copies (`promote.include`, `promote.owners`)
     */
    public function __construct(
        public StoreName $name,
        public array $layers,
        public array $owners,
        public bool $strict,
        public bool $inherits,
        public bool $locksOverridable = false,
        public bool $bootstrap = false,
        public ?string $table = null,
        public StoreMode $mode = StoreMode::Polymorphic,
        public ?StoreName $fallback = null,
        public array $approvalKeys = [],
        public bool $softDeletes = false,
        public bool $autoPromote = false,
        public PromotionSelection $promotion = new PromotionSelection,
        public ?string $ownerTable = null,
        public string $ownerKey = 'id',
    ) {}

    /** Whether a write of this key becomes a change request. */
    public function requiresApproval(Key $key): bool
    {
        return array_any($this->approvalKeys, static fn (string $glob): bool => fnmatch($glob, $key->value, FNM_NOESCAPE));
    }

    /** Whether this store has an active database layer, which holds its change requests. */
    public function acceptsChangeRequests(): bool
    {
        return $this->database() instanceof DatabaseDriver;
    }

    /**
     * The database layer that holds this store's change requests.
     *
     * @throws ApprovalUnavailable when the store has no active database layer
     */
    public function changeRequestsDatabase(): DatabaseDriver
    {
        return $this->database() ?? throw new ApprovalUnavailable(sprintf('Store [%s] cannot hold change requests: it needs an active database layer.', $this->name));
    }

    public function isDedicated(): bool
    {
        return $this->mode === StoreMode::Dedicated;
    }

    /** Whether any layer of this store is a database layer, active or not. */
    public function hasDatabaseLayer(): bool
    {
        return array_any($this->layers, fn (Layer $layer): bool => $layer->driver instanceof DatabaseBacked);
    }

    /** The first active database layer's driver, or null. */
    public function database(): ?DatabaseDriver
    {
        foreach ($this->layers as $layer) {
            if ($layer->driver instanceof DatabaseBacked && $layer->driver->isActive()) {
                return $layer->driver->database();
            }
        }

        return null;
    }

    /** The first writable JSON layer: the runtime file that promotion copies from. */
    public function runtimeFile(): ?JsonDriver
    {
        foreach ($this->layers as $layer) {
            if ($layer->driver instanceof JsonDriver && $layer->driver->isWritable()) {
                return $layer->driver;
            }
        }

        return null;
    }

    /**
     * The first active database layer's driver.
     *
     * @throws StoreNotWritable with `$why` appended to the message
     */
    public function requireDatabase(string $why): DatabaseDriver
    {
        return $this->database() ?? throw new StoreNotWritable(sprintf('Store [%s] has no active database layer%s', $this->name, $why));
    }

    /**
     * Run a write and its audit row in one transaction on the store's database connection.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $database = $this->database();

        return $database instanceof DatabaseDriver ? $database->transaction($callback, $this->name) : $callback();
    }

    public function accepts(string $alias): bool
    {
        return in_array($alias, $this->owners, true);
    }

    /** The first writable layer, where writes go. */
    public function writableLayer(): Layer
    {
        foreach ($this->layers as $layer) {
            if ($layer->driver->isWritable()) {
                return $layer;
            }
        }

        throw new StoreNotWritable(sprintf('Store [%s] has no writable layer.', $this->name));
    }

    /** Whether any layer of this store is read through the settings cache. */
    public function isCached(): bool
    {
        return array_any($this->layers, fn (Layer $layer): bool => $layer->driver instanceof CachingDriver);
    }

    /** Forget one scope's cached bag in every cached layer once the transaction commits. */
    public function forgetCached(Scope $scope): void
    {
        foreach ($this->cachedLayers() as $driver) {
            $driver->forgetScope($this->name, $scope);
        }
    }

    /** Forget every cached bag of this store, for every tenant. */
    public function forgetCachedStore(): void
    {
        foreach ($this->cachedLayers() as $driver) {
            $driver->forgetStore($this->name);
        }
    }

    /**
     * Load these scopes into every cached layer and return how many records they hold.
     *
     * @param list<Scope> $scopes
     */
    public function warmCache(array $scopes): int
    {
        $records = 0;

        foreach ($this->cachedLayers() as $driver) {
            if ($driver->isActive()) {
                $records += $driver->load($this->name, $scopes)->count();
            }
        }

        return $records;
    }

    /**
     * Every layer's records for these scopes, one read per layer.
     *
     * @param list<Scope> $scopes
     *
     * @return array<string, RecordSet>
     */
    public function load(array $scopes): array
    {
        $loaded = [];

        foreach ($this->layers as $layer) {
            $loaded[$layer->name] = $layer->driver->load($this->name, $scopes);
        }

        return $loaded;
    }

    /** @return list<CachingDriver> */
    private function cachedLayers(): array
    {
        return array_values(array_filter(array_map(static fn (Layer $layer): ?CachingDriver => $layer->driver instanceof CachingDriver ? $layer->driver : null, $this->layers)));
    }
}
