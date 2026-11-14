<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Diagnostics\Finding;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorResult;
use Simtabi\Laranail\Settings\Services\Database\SchemaInspector;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/**
 * The findings of `laranail::settings.doctor`. Read-only.
 *
 * Per store with a database layer: readiness, a runtime file a store set to promote
 * automatically has not promoted, missing columns, scopes that name a class or an
 * alias the store does not list, category links to another store, keys outside their category,
 * encrypted values no key can read, and overdue change requests. Then: every code or config
 * definition whose key sits outside its category, and persisted configuration entries skipped as
 * invalid.
 */
final readonly class DiagnoseSettings
{
    /** The values-table columns every store has; owner columns depend on the store's mode. */
    private const array VALUES_COLUMNS = ['id', 'uid', 'scope', 'category_id', 'category_store', 'group', 'key', 'locale', 'value', 'type', 'label', 'description', 'is_encrypted', 'is_locked', 'is_public', 'autoload', 'sort_order', 'meta', 'version', 'created_at', 'updated_at', 'deleted_at'];

    private const array CATEGORY_COLUMNS = ['id', 'store', 'parent_id', 'title', 'slug', 'description', 'icon', 'sort_order', 'is_system', 'meta', 'created_at', 'updated_at'];

    private const array DEFINITION_COLUMNS = ['id', 'store', 'slug', 'key', 'group', 'category_id', 'type', 'default', 'label', 'description', 'rules', 'is_encrypted', 'is_public', 'is_locked', 'autoload', 'inherits', 'overridable_by', 'is_system', 'meta', 'created_at', 'updated_at'];

    private const array AUDIT_COLUMNS = ['id', 'occurred_at', 'action', 'outcome', 'store', 'scope', 'key', 'locale', 'version_from', 'version_to', 'change_request', 'actor_type', 'actor_alias', 'actor_id', 'channel', 'ip', 'user_agent', 'request_id', 'reason', 'meta'];

    private const array HISTORY_COLUMNS = ['id', 'store', 'setting_ref', 'scope', 'key', 'locale', 'operation', 'old_value', 'new_value', 'old_type', 'new_type', 'old_encrypted', 'new_encrypted', 'version', 'causer_type', 'causer_alias', 'causer_id', 'reason', 'created_at'];

    private const array CHANGE_REQUEST_COLUMNS = ['id', 'store', 'scope', 'key', 'locale', 'operation', 'payload', 'type', 'is_encrypted', 'base_version', 'status', 'requested_by_type', 'requested_by_alias', 'requested_by_id', 'approvals', 'decided_by_type', 'decided_by_alias', 'decided_by_id', 'decided_at', 'reason', 'decision_reason', 'expires_at', 'created_at', 'updated_at'];

    private const array STORES_COLUMNS = ['id', 'kind', 'name', 'config', 'created_at', 'updated_at'];

    public function __construct(
        private StoreRegistry $stores,
        private LaravelValueEncrypter $encrypter,
        private PersistedConfiguration $persisted,
        private DefinitionRegistry $definitions,
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

            if ($store->autoPromote) {
                $findings[] = $this->autoPromotion($store);
            }

            $findings[] = $this->columns($store, $database);

            if ($store->isDedicated()) {
                $findings[] = $this->ownerKeyType($store, $database);
            }

            $findings[] = $this->scopes($store, $database);
            $findings[] = $this->categories($store, $database);
            $findings[] = $this->categoryKeys($store, $database);

            $findings[] = $this->encryption($store, $database, $this->encrypter);
            $findings[] = $this->changeRequests($store, $database);
        }

        $findings[] = $this->definitionCategories();

        $problems = $this->persisted->problems();
        $findings[] = new Finding('persisted configuration', 'Every persisted store and layer entry is valid', $problems === []
            ? DoctorResult::pass('Every persisted entry is valid (or persisted configuration is off).')
            : DoctorResult::warn('Persisted entries skipped as invalid: ' . implode('; ', $problems), ['problems' => $problems]));

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

    /** A store set to promote automatically has no runtime file left once its database is ready. */
    private function autoPromotion(Store $store): Finding
    {
        $file = $store->runtimeFile();

        $waiting = $file instanceof JsonDriver && $file->hasFile($store->name) && array_any($file->all($store->name), $store->promotion->includes(...));

        return new Finding("{$store->name}: promotion", 'A store set to promote automatically has promoted its runtime file', $waiting
            ? DoctorResult::warn('The runtime file has not been promoted although the database is ready; check the log for a failed promotion, or run laranail::settings.promote.')
            : DoctorResult::pass('No runtime file is waiting to be promoted.'));
    }

    /** A dedicated store's `owner_id` column has the type of the owner table's key. */
    private function ownerKeyType(Store $store, DatabaseDriver $database): Finding
    {
        $inspector = new SchemaInspector($database);
        $ownerId = $inspector->idType($database->tableFor($store->name), 'owner_id');
        $ownerKey = $store->ownerTable === null ? null : $inspector->idType($store->ownerTable, $store->ownerKey);
        $label = 'owner_id has the type of the owner table\'s key';

        return match (true) {
            $ownerId === null || $ownerKey === null => new Finding("{$store->name}: owner key", $label, DoctorResult::skip('The owner table or its key column could not be read.')),
            $ownerId === $ownerKey                  => new Finding("{$store->name}: owner key", $label, DoctorResult::pass(sprintf('owner_id and %s.%s are both %s.', $store->ownerTable, $store->ownerKey, $ownerId))),
            default                                 => new Finding("{$store->name}: owner key", $label, DoctorResult::warn(sprintf('owner_id is %s but %s.%s is %s; set stores.%s.owner.type to match and migrate again.', $ownerId, $store->ownerTable, $store->ownerKey, $ownerKey, $store->name))),
        };
    }

    /** Every column the package uses exists on the store's tables. */
    private function columns(Store $store, DatabaseDriver $database): Finding
    {
        $names = ModelContext::names();
        $tenant = $database->hasTenantColumn() ? ['tenant'] : [];
        $owner = $store->isDedicated() ? ['owner_id'] : ['settable_type', 'settable_id'];
        $expected = [
            $database->tableFor($store->name) => [...self::VALUES_COLUMNS, ...$owner, ...$tenant],
            $names->categories()              => [...self::CATEGORY_COLUMNS, ...$tenant],
            $names->audit()                   => [...self::AUDIT_COLUMNS, ...$tenant],
            $names->history()                 => [...self::HISTORY_COLUMNS, ...$tenant],
            $names->changeRequests()          => [...self::CHANGE_REQUEST_COLUMNS, ...$tenant],
        ];

        if ($database->connectionName() === $this->stores->default()->database()?->connectionName()) {
            $expected[$names->definitions()] = self::DEFINITION_COLUMNS;
            $expected[$names->stores()] = self::STORES_COLUMNS;
        }

        $inspector = new SchemaInspector($database);
        $missing = [];

        foreach ($expected as $table => $columns) {
            foreach (array_diff($columns, $inspector->columns($table) ?? []) as $column) {
                $missing[] = $table . '.' . $column;
            }
        }

        return new Finding("{$store->name}: schema", 'The tables have every column the package uses', $missing === []
            ? DoctorResult::pass('Every column is present.')
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

    /**
     * Encrypted values no current or previous key can read (a key rotated without `reencrypt`,
     * or with the old key dropped from APP_PREVIOUS_KEYS). The current tenant's rows.
     */
    private function encryption(Store $store, DatabaseDriver $database, LaravelValueEncrypter $encrypter): Finding
    {
        $unreadable = [];
        $encrypted = 0;

        try {
            $records = $database->load($store->name, array_values(array_filter(array_map(Scope::fromString(...), $database->scopes($store->name)))))->all();
        } catch (SettingsException $exception) {
            return new Finding("{$store->name}: encryption", 'Every encrypted value can be decrypted', DoctorResult::fail($exception->getMessage()));
        }

        foreach ($records as $record) {
            if (! $record->encrypted || $record->payload === null) {
                continue;
            }

            $encrypted++;

            try {
                $encrypter->decrypt($record->payload);
            } catch (SettingsException) {
                $unreadable[] = $record->identity();
            }
        }

        return new Finding("{$store->name}: encryption", 'Every encrypted value can be decrypted', $unreadable === []
            ? DoctorResult::pass(sprintf('%d encrypted value(s), all readable.', $encrypted))
            : DoctorResult::fail(sprintf('%d encrypted value(s) cannot be decrypted with the current or previous keys (put the old key in APP_PREVIOUS_KEYS, then run laranail::settings.reencrypt): %s', count($unreadable), implode(', ', $unreadable)), ['unreadable' => $unreadable]));
    }

    /** Open change requests past their expiry: prune-changes has not run. */
    private function changeRequests(Store $store, DatabaseDriver $database): Finding
    {
        $overdue = $database->attempt($store->name, static fn (): int => SettingChangeRequest::on($database->connectionName())->overdue(CarbonImmutable::now())->count());

        return new Finding("{$store->name}: change requests", 'No open change request is past its expiry', $overdue === 0
            ? DoctorResult::pass('No overdue change requests.')
            : DoctorResult::warn(sprintf('%d open change request(s) are past their expiry: schedule laranail::settings.prune-changes.', $overdue)));
    }

    private function categories(Store $store, DatabaseDriver $database): Finding
    {
        $count = new SchemaInspector($database)->crossStoreCategoryLinks($store->name);

        return new Finding("{$store->name}: categories", 'Settings only use categories of their own store', $count === 0
            ? DoctorResult::pass('Every category link stays inside the store.')
            : DoctorResult::fail(sprintf('%d row(s) point at a category of another store.', $count)));
    }

    /** Values whose key does not start with their category's slug: a warning, not a failure. */
    private function categoryKeys(Store $store, DatabaseDriver $database): Finding
    {
        $keys = new SchemaInspector($database)->keysOutsideTheirCategory($store->name);

        return new Finding("{$store->name}: category keys", 'Each key starts with its category\'s slug', $keys === []
            ? DoctorResult::pass('Every categorised key starts with its category\'s slug.')
            : DoctorResult::warn(sprintf('%d key(s) do not start with their category\'s slug: %s', count($keys), implode(', ', $keys)), ['keys' => $keys]));
    }

    /** Code and config definitions whose key does not start with their category's slug. */
    private function definitionCategories(): Finding
    {
        $keys = [];

        foreach ($this->definitions->all() as $definition) {
            if ($definition->category !== null && $definition->key->group() !== $definition->category) {
                $keys[] = sprintf('%s:%s (%s)', $definition->store, $definition->key, $definition->category);
            }
        }

        return new Finding('definitions: category keys', 'Each defined key starts with its category\'s slug', $keys === []
            ? DoctorResult::pass('Every categorised definition starts with its category\'s slug.')
            : DoctorResult::warn(sprintf('%d definition(s) do not start with their category\'s slug: %s', count($keys), implode(', ', $keys)), ['keys' => $keys]));
    }
}
