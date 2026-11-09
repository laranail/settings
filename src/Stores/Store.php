<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Closure;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;

/**
 * A configured store: its layers in read order, the owner aliases it accepts, and its policies.
 * `locksOverridable` (`locks.overridable`) lets an actor holding `override_lock` write a locked
 * setting; it is off by default, so a lock binds everyone. `bootstrap` lets the database layer be
 * skipped while the application is being installed (§7.4); `table` is the configured values
 * table (`stores.<name>.table`), null for the store name.
 */
final readonly class Store
{
    /**
     * @param list<Layer> $layers
     * @param list<string> $owners
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
    ) {}

    /** Whether any layer of this store is a database layer, whether or not it is active. */
    public function hasDatabaseLayer(): bool
    {
        return array_any($this->layers, fn (Layer $layer): bool => $layer->driver instanceof DatabaseBacked);
    }

    /** The first active database layer's driver, or null (none, or skipped during bootstrap). */
    public function database(): ?DatabaseDriver
    {
        foreach ($this->layers as $layer) {
            if ($layer->driver instanceof DatabaseBacked && $layer->driver->isActive()) {
                return $layer->driver->database();
            }
        }

        return null;
    }

    /**
     * Run a write and everything that must commit with it (the audit row) in one transaction on
     * the store's database connection. A store without an active database layer just runs it.
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

    /**
     * Every layer's records for these scopes, in layer order: one read per layer.
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
}
