<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Throwable;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Diagnostics\Finding;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorResult;

/**
 * What `laranail::settings.doctor` reports, per store with a database layer: readiness (the
 * connection answers and the tables exist), schema drift (columns this release reads that the
 * tables lack), scopes naming a class instead of a morph alias or an alias the store does not
 * list (G-6, G-30), and rows whose category belongs to another store (G-33). Read-only.
 */
final readonly class DiagnoseSettings
{
    /** The columns this release reads and writes, per table. */
    private const array VALUES_COLUMNS = ['id', 'settable_type', 'settable_id', 'scope', 'category_id', 'category_store', 'group', 'key', 'slug', 'uid', 'locale', 'value', 'type', 'is_encrypted', 'is_locked', 'is_public', 'autoload', 'sort_order', 'meta', 'version', 'created_at', 'updated_at'];

    private const array CATEGORY_COLUMNS = ['id', 'store', 'parent_id', 'title', 'slug', 'description', 'icon', 'sort_order', 'is_system', 'meta', 'created_at', 'updated_at'];

    private const array AUDIT_COLUMNS = ['id', 'occurred_at', 'action', 'outcome', 'store', 'scope', 'key', 'locale', 'version_from', 'version_to', 'change_request', 'actor_type', 'actor_alias', 'actor_id', 'channel', 'ip', 'user_agent', 'request_id', 'reason', 'meta'];

    public function __construct(
        private StoreRegistry $stores,
    ) {}

    /** @return list<Finding> */
    public function handle(): array
    {
        $findings = [];

        foreach ($this->stores->all() as $name => $store) {
            $database = $this->databaseOf($store);

            if (! $database instanceof DatabaseDriver) {
                $findings[] = new Finding("{$name}: layers", 'The store\'s layers', DoctorResult::skip('No database layer; values live in file layers only.'));

                continue;
            }

            if (! $database->isReady($store->name)) {
                $findings[] = new Finding("{$name}: readiness", 'The database layer answers and its table exists', $store->bootstrap
                    ? DoctorResult::warn(sprintf('Table [%s] on [%s] is not ready; bootstrap mode may be skipping it.', $database->tableFor($store->name), $database->connectionName()))
                    : DoctorResult::fail(sprintf('Table [%s] on [%s] is not ready: run the migrations.', $database->tableFor($store->name), $database->connectionName())));

                continue;
            }

            $findings[] = new Finding("{$name}: readiness", 'The database layer answers and its table exists', DoctorResult::pass(sprintf('Table [%s] on [%s] is ready.', $database->tableFor($store->name), $database->connectionName())));
            $findings[] = $this->drift($store, $database);
            $findings[] = $this->scopes($store, $database);
            $findings[] = $this->categories($store, $database);
        }

        return $findings;
    }

    private function databaseOf(Store $store): ?DatabaseDriver
    {
        foreach ($store->layers as $layer) {
            if ($layer->driver instanceof DatabaseBacked) {
                return $layer->driver->database();
            }
        }

        return null;
    }

    private function drift(Store $store, DatabaseDriver $database): Finding
    {
        $missing = [];

        foreach ([
            $database->tableFor($store->name) => self::VALUES_COLUMNS,
            $database->names()->categories()  => self::CATEGORY_COLUMNS,
            $database->names()->audit()       => self::AUDIT_COLUMNS,
        ] as $table => $columns) {
            try {
                $present = $database->connection()->getSchemaBuilder()->getColumnListing($table);
            } catch (Throwable) {
                $present = [];
            }

            foreach (array_diff($columns, $present) as $column) {
                $missing[] = $table . '.' . $column;
            }
        }

        return new Finding("{$store->name}: schema", 'The tables have every column this release uses', $missing === []
            ? DoctorResult::pass('No schema drift.')
            : DoctorResult::fail('Missing columns (publish and run the latest migrations): ' . implode(', ', $missing), ['missing' => $missing]));
    }

    private function scopes(Store $store, DatabaseDriver $database): Finding
    {
        $classes = [];
        $unlisted = [];

        foreach ($database->scopes($store->name) as $scope) {
            if ($scope === Scope::GLOBAL) {
                continue;
            }

            $alias = explode(':', $scope, 2)[0];

            if (str_contains($alias, '\\')) {
                $classes[$alias] = true;
            } elseif (! $store->accepts($alias)) {
                $unlisted[$alias] = true;
            }
        }

        if ($classes !== []) {
            return new Finding("{$store->name}: scopes", 'Scopes use morph aliases the store lists', DoctorResult::fail('Scopes name classes instead of morph aliases: ' . implode(', ', array_keys($classes)), ['classes' => array_keys($classes)]));
        }

        return new Finding("{$store->name}: scopes", 'Scopes use morph aliases the store lists', $unlisted === []
            ? DoctorResult::pass('Every scope uses a listed alias.')
            : DoctorResult::warn('Scopes use aliases the store does not list (renamed or removed?): ' . implode(', ', array_keys($unlisted)), ['aliases' => array_keys($unlisted)]));
    }

    private function categories(Store $store, DatabaseDriver $database): Finding
    {
        $count = $database->attempt($store->name, static fn (): int => $database->connection()->table($database->tableFor($store->name))
            ->whereNotNull('category_id')
            ->where(static fn (Builder $query): Builder => $query->whereNull('category_store')->orWhere('category_store', '!=', $store->name->value))
            ->count());

        return new Finding("{$store->name}: categories", 'Settings only use categories of their own store', $count === 0
            ? DoctorResult::pass('Every category link stays inside the store.')
            : DoctorResult::fail(sprintf('%d row(s) point at a category of another store.', $count)));
    }
}
