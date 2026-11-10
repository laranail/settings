<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * Where a cached bag's rows live: the connection name, the database that connection points at,
 * the store and its table. Every one of them is in every cache key (G-37), because the `global`
 * scope is spelled the same in every database and a shared cache would otherwise answer one
 * database's read from another's rows. The tenant is added by the cache itself, per call.
 */
final readonly class CacheTarget
{
    public function __construct(
        public string $connection,
        public string $database,
        public StoreName $store,
        public string $table,
    ) {}

    /** @return list<string> the components that name the store's rows, in key order */
    public function parts(): array
    {
        return [$this->connection, $this->database, $this->store->value, $this->table];
    }
}
