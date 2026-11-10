<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Database\SchemaInspector;

/**
 * The tables `install` would create that already exist and are not this package's (D-4). A bare
 * `settings` table is a plausible collision with an application's own; installing over it would
 * fail half-way or, worse, write into it. A table is recognised as the package's by the columns
 * only it has. A database that cannot be reached is not reported: there is nothing to collide
 * with yet.
 */
final readonly class FindForeignTables
{
    private const array SIGNATURES = [
        'values'     => ['scope', 'key', 'locale', 'version'],
        'categories' => ['store', 'slug', 'parent_id'],
        'audit'      => ['occurred_at', 'outcome', 'actor_type'],
    ];

    public function __construct(
        private StoreRegistry $stores,
    ) {}

    /** @return list<string> `connection.table` of every foreign table */
    public function handle(): array
    {
        $foreign = [];

        foreach ($this->stores->all() as $store) {
            foreach ($store->layers as $layer) {
                if (! $layer->driver instanceof DatabaseBacked) {
                    continue;
                }

                $database = $layer->driver->database();
                $tables = [
                    $database->tableFor($store->name) => self::SIGNATURES['values'],
                    $database->names()->categories()  => self::SIGNATURES['categories'],
                    $database->names()->audit()       => self::SIGNATURES['audit'],
                ];

                $inspector = new SchemaInspector($database);

                foreach ($tables as $table => $signature) {
                    // Unreachable (null): nothing exists to collide with.
                    $columns = $inspector->hasTable($table) === true ? $inspector->columns($table) : null;

                    if ($columns !== null && array_diff($signature, $columns) !== []) {
                        $foreign[$database->connectionName() . '.' . $table] = true;
                    }
                }
            }
        }

        return array_keys($foreign);
    }
}
