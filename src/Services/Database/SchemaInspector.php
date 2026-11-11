<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Database;

use Throwable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;

/**
 * Inspects the live database on one connection; a failed inspection returns null instead of throwing.
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

    /** The database type of a table's key column (`id` by default), or null when it cannot be read. */
    public function idType(string $table, string $column = 'id'): ?string
    {
        try {
            $columns = $this->database->connection()->getSchemaBuilder()->getColumns($table);
        } catch (Throwable) {
            return null;
        }

        foreach ($columns as $candidate) {
            if (($candidate['name'] ?? null) === $column) {
                return (string) ($candidate['type_name'] ?? '?');
            }
        }

        return null;
    }

    /** How many rows link to a category of another store. */
    public function crossStoreCategoryLinks(StoreName $store): int
    {
        $database = $this->database;

        return $database->attempt($store, static fn (): int => $database->connection()->table($database->tableFor($store))
            ->whereNotNull('category_id')
            ->where(static fn (Builder $query): Builder => $query->whereNull('category_store')->orWhere('category_store', '!=', $store->value))
            ->count());
    }

    /**
     * Keys whose first segment is not the slug of the category they belong to.
     *
     * @return list<string> `key (category)` for each mismatched row
     */
    public function keysOutsideTheirCategory(StoreName $store): array
    {
        $database = $this->database;
        $values = $database->tableFor($store);
        $categories = $database->names()->categories();

        $rows = $database->attempt($store, static fn (): array => $database->connection()->table($values)
            ->join($categories, static fn (JoinClause $join): JoinClause => $join
                ->on($categories . '.id', '=', $values . '.category_id')
                ->on($categories . '.store', '=', $values . '.category_store'))
            ->orderBy($values . '.key')
            ->get([$values . '.key as key', $categories . '.slug as slug'])
            ->all());

        $mismatched = [];

        foreach ($rows as $row) {
            $key = is_object($row) && property_exists($row, 'key') && is_string($row->key) ? $row->key : '';
            $slug = is_object($row) && property_exists($row, 'slug') && is_string($row->slug) ? $row->slug : '';

            if (explode('.', $key, 2)[0] !== $slug) {
                $mismatched[] = sprintf('%s (%s)', $key, $slug);
            }
        }

        return array_values(array_unique($mismatched));
    }
}
