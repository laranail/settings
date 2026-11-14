<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * Where a cached bag's rows live: connection, database, store and table. All of them go into the
 * cache key so a shared cache never answers one database's read with another's rows.
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
