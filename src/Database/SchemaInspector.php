<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use Throwable;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;

/**
 * What the live database on one connection holds, for `doctor` and for install's foreign-table
 * check: tables, columns, the `id` column's type, and rows whose category belongs to another
 * store. Introspection that fails reports "unknown" (null) rather than throwing: a check that
 * cannot look is not a check that found something.
 */
final readonly class SchemaInspector
{
    public function __construct(
        private DatabaseDriver $database,
    ) {}

    /** Whether the table exists; null when the database could not be asked. */
    public function hasTable(string $table): ?bool
    {
        try {
            return $this->database->connection()->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The table's columns; null when the database could not be asked.
     *
     * @return list<string>|null
     */
    public function columns(string $table): ?array
    {
        try {
            return array_values($this->database->connection()->getSchemaBuilder()->getColumnListing($table));
        } catch (Throwable) {
            return null;
        }
    }

    /** The database type of the table's `id` column; null when there is none or it cannot be read. */
    public function idType(string $table): ?string
    {
        try {
            $columns = $this->database->connection()->getSchemaBuilder()->getColumns($table);
        } catch (Throwable) {
            return null;
        }

        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'id') {
                return (string) ($column['type_name'] ?? '?');
            }
        }

        return null;
    }

    /** Rows of a store's values table whose category link names another store (or none). */
    public function crossStoreCategoryLinks(StoreName $store): int
    {
        $database = $this->database;

        return $database->attempt($store, static fn (): int => $database->connection()->table($database->tableFor($store))
            ->whereNotNull('category_id')
            ->where(static fn (Builder $query): Builder => $query->whereNull('category_store')->orWhere('category_store', '!=', $store->value))
            ->count());
    }
}
