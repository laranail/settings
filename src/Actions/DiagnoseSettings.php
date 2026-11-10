<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Diagnostics\Finding;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorResult;
use Simtabi\Laranail\Settings\Services\Database\SchemaInspector;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;

/**
 * What `laranail::settings.doctor` reports, per store with a database layer: readiness (the
 * connection answers and the tables exist), schema drift (columns this release reads that the
 * tables lack), scopes naming a class instead of a morph alias or an alias the store does not
 * list (G-6, G-30), and rows whose category belongs to another store (G-33). Read-only.
 *
 * Schema drift is three comparisons (§4.0): the schema file against the configuration (a switch
 * changed after migrating changes nothing until a migration does), the file's shape version
 * against this release's (an upgrade migration that was never published), and the live tables
 * against the recorded shape (missing columns fail; columns the shape does not have, such as
 * `deleted_at` on a store without soft deletes, warn).
 */
final readonly class DiagnoseSettings
{
    /** The values-table columns every shape has; the rest depend on the switches. */
    private const array VALUES_COLUMNS = ['id', 'scope', 'category_id', 'category_store', 'group', 'key', 'locale', 'value', 'type', 'label', 'description', 'is_encrypted', 'is_locked', 'is_public', 'autoload', 'sort_order', 'meta', 'version', 'created_at', 'updated_at'];

    private const array CATEGORY_COLUMNS = ['id', 'store', 'parent_id', 'title', 'slug', 'description', 'icon', 'sort_order', 'is_system', 'meta', 'created_at', 'updated_at'];

    private const array DEFINITION_COLUMNS = ['id', 'store', 'slug', 'key', 'group', 'category_id', 'type', 'default', 'label', 'description', 'rules', 'is_encrypted', 'is_public', 'is_locked', 'autoload', 'inherits', 'overridable_by', 'is_system', 'meta', 'created_at', 'updated_at'];

    private const array AUDIT_COLUMNS = ['id', 'occurred_at', 'action', 'outcome', 'store', 'scope', 'key', 'locale', 'version_from', 'version_to', 'change_request', 'actor_type', 'actor_alias', 'actor_id', 'channel', 'ip', 'user_agent', 'request_id', 'reason', 'meta'];

    public function __construct(
        private StoreRegistry $stores,
        private ?SchemaShapeRepository $shapes = null,
    ) {}

    /** @return list<Finding> */
    public function handle(): array
    {
        $findings = $this->shapes instanceof SchemaShapeRepository ? $this->schemaFile($this->shapes) : [];

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

    /**
     * The schema file against the configuration and against this release.
     *
     * @return list<Finding>
     */
    private function schemaFile(SchemaShapeRepository $shapes): array
    {
        $name = 'schema file';
        $description = 'The recorded schema matches the configuration and this release';

        try {
            $recorded = $shapes->recorded();
            $configured = $shapes->configured();
        } catch (SettingsException $exception) {
            return [new Finding($name, $description, DoctorResult::fail($exception->getMessage()))];
        }

        if (! $recorded instanceof SchemaShape) {
            return [new Finding($name, $description, DoctorResult::warn(sprintf('No schema file at [%s]; the shape is read from configuration. Run laranail::settings.install to record it.', $shapes->path())))];
        }

        $findings = [];

        if ($recorded->version < SchemaShape::VERSION) {
            $findings[] = new Finding('schema version', 'The schema is at this release\'s shape version', DoctorResult::fail(sprintf('The schema file is at shape version %d; this release needs %d. Publish and run the upgrade migrations, then record the new version.', $recorded->version, SchemaShape::VERSION)));
        }

        foreach ($this->stores->all() as $storeName => $store) {
            if ($store->hasDatabaseLayer() && ! $recorded->hasStore($store->name)) {
                $findings[] = new Finding("{$storeName}: recorded", 'The store is in the schema file', DoctorResult::fail(sprintf('Store [%s] has a database layer but no entry in the schema file: run laranail::settings.make-store %s.', $storeName, $storeName)));
            }
        }

        $differences = array_values(array_filter(
            $configured->differencesFrom($recorded),
            static fn (string $line): bool => ! str_starts_with($line, 'stores.') || array_any(array_keys($recorded->stores), static fn (string $store): bool => str_starts_with($line, "stores.{$store}.")),
        ));

        $findings[] = new Finding($name, $description, $differences === []
            ? DoctorResult::pass('The configuration matches the recorded schema.')
            : DoctorResult::warn('The configuration differs from the recorded schema (the recorded one is in force; a migration is needed to change it): ' . implode('; ', $differences), ['differences' => $differences]));

        return $findings;
    }

    private function drift(Store $store, DatabaseDriver $database): Finding
    {
        $shape = $database->shape();
        $schema = $shape->store($store->name);
        $missing = [];
        $unexpected = [];
        $tenant = $shape->tenancy->hasColumn() ? ['tenant'] : [];
        $expected = [
            $database->tableFor($store->name) => [...self::VALUES_COLUMNS, ...$tenant, ...$this->switchColumns($shape, $schema->isDedicated(), $schema->softDeletes)],
            $shape->names->categories()       => [...self::CATEGORY_COLUMNS, ...$tenant, ...($shape->categoriesSoftDeletes ? ['deleted_at'] : [])],
            $shape->names->audit()            => [...self::AUDIT_COLUMNS, ...$tenant],
        ];

        if ($shape->definitionsTable) {
            $expected[$shape->names->definitions()] = self::DEFINITION_COLUMNS;
        }

        $inspector = new SchemaInspector($database);

        foreach ($expected as $table => $columns) {
            $present = $inspector->columns($table) ?? [];

            foreach (array_diff($columns, $present) as $column) {
                $missing[] = $table . '.' . $column;
            }

            foreach (array_diff(array_intersect($present, ['deleted_at', 'slug', 'uid', 'settable_type', 'settable_id', 'owner_id', 'tenant']), $columns) as $column) {
                $unexpected[] = $table . '.' . $column;
            }
        }

        $idType = $this->idTypeDrift($inspector, $database->tableFor($store->name), $shape->ids);

        if ($missing !== []) {
            return new Finding("{$store->name}: schema", 'The tables have every column the recorded schema uses', DoctorResult::fail('Missing columns (publish and run the latest migrations): ' . implode(', ', $missing), ['missing' => $missing]));
        }

        if ($idType !== null) {
            return new Finding("{$store->name}: schema", 'The tables have every column the recorded schema uses', DoctorResult::fail($idType));
        }

        return new Finding("{$store->name}: schema", 'The tables have every column the recorded schema uses', $unexpected === []
            ? DoctorResult::pass('No schema drift.')
            : DoctorResult::warn('Columns the recorded schema does not have (a switch changed without a migration?): ' . implode(', ', $unexpected), ['unexpected' => $unexpected]));
    }

    /** @return list<string> the values-table columns the switches add */
    private function switchColumns(SchemaShape $shape, bool $dedicated, bool $softDeletes): array
    {
        return [
            ...($dedicated ? ['owner_id'] : ['settable_type', 'settable_id']),
            ...($shape->identity->hasSlug() ? ['slug'] : []),
            ...($shape->identity->hasUid() ? ['uid'] : []),
            ...($softDeletes ? ['deleted_at'] : []),
        ];
    }

    /** A message when the table's `id` column is not of the recorded key type, else null. */
    private function idTypeDrift(SchemaInspector $inspector, string $table, IdType $type): ?string
    {
        $typeName = $inspector->idType($table);

        if ($typeName === null) {
            return null;
        }

        $integer = str_contains(strtolower($typeName), 'int');

        return $integer === ($type === IdType::Bigint) ? null : sprintf('Table [%s] has an id of type [%s], but the recorded key type is [%s].', $table, $typeName, $type->value);
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
        $count = new SchemaInspector($database)->crossStoreCategoryLinks($store->name);

        return new Finding("{$store->name}: categories", 'Settings only use categories of their own store', $count === 0
            ? DoctorResult::pass('Every category link stays inside the store.')
            : DoctorResult::fail(sprintf('%d row(s) point at a category of another store.', $count)));
    }
}
