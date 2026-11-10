<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Closure;
use Exception;
use PDOException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Models\Setting;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Illuminate\Database\UniqueConstraintViolationException;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

/**
 * Stores records in a database table through the query builder.
 *
 * Writes are version-checked and raise {@see ConcurrentModification} on a conflict. Database
 * errors surface as {@see DriverUnavailable} or {@see DriverFailure}, never as PDO exceptions.
 * Each instance serves one store: its table, soft deletes and dedicated owner come from that
 * store's configuration.
 */
final readonly class DatabaseDriver implements DatabaseBacked
{
    private const array COLUMNS = ['scope', 'key', 'locale', 'value', 'type', 'is_encrypted', 'is_locked', 'is_public', 'group', 'version', 'updated_at'];

    /**
     * @param ?string $table the store's configured table (`stores.<name>.table`); null uses the store name
     * @param Closure(): SchemaReadinessInterface $probe db-tools' schema readiness, resolved only after a database error
     * @param bool $tenantColumn whether the tables carry a tenant column (`tenancy.mode` = column)
     * @param (Closure(): ?string)|null $tenant the current tenant; null is no tenant
     * @param ?string $ownerAlias the one owner alias of a dedicated store; null for a polymorphic store
     * @param ?string $fallback the store a dedicated store's global values live in
     */
    public function __construct(
        private ConnectionResolverInterface $db,
        private ?string $connection,
        private ?string $table,
        private bool $writable,
        private Closure $probe,
        private bool $tenantColumn = false,
        private ?Closure $tenant = null,
        private bool $softDeletes = false,
        private ?string $ownerAlias = null,
        private IdType $ownerKeyType = IdType::Bigint,
        private ?string $fallback = null,
    ) {}

    public function database(): self
    {
        return $this;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /** The connection this layer uses, or the default connection. */
    public function connectionName(): string
    {
        return $this->connection ?? $this->db->getDefaultConnection();
    }

    public function tableFor(StoreName $store): string
    {
        return Setting::tableFor($store, $this->table);
    }

    /** The current tenant in column-mode tenancy; '' for central rows and in other modes. */
    public function tenant(): string
    {
        if (! $this->tenantColumn || ! $this->tenant instanceof Closure) {
            return '';
        }

        $tenant = ($this->tenant)();

        return is_string($tenant) ? $tenant : '';
    }

    /** Whether the tables carry a tenant column (`tenancy.mode` = column). */
    public function hasTenantColumn(): bool
    {
        return $this->tenantColumn;
    }

    public function names(): SchemaNames
    {
        return ModelContext::names();
    }

    public function connection(): Connection
    {
        $connection = $this->db->connection($this->connection);

        return $connection instanceof Connection ? $connection : throw new DriverFailure(sprintf('Connection [%s] is not a database connection.', $this->connectionName()));
    }

    /** Whether the connection answers and the store's table exists. Never throws. */
    public function isReady(StoreName $store): bool
    {
        try {
            return ($this->probe)()->isReady([$this->tableFor($store)], $this->connectionName());
        } catch (Exception) {
            return false;
        }
    }

    public function load(StoreName $store, array $scopes): RecordSet
    {
        $values = array_values(array_unique(array_map(static fn (Scope $scope): string => $scope->value, $scopes)));

        if ($values === []) {
            return new RecordSet;
        }

        return $this->attempt($store, function () use ($store, $values): RecordSet {
            $records = [];

            foreach (array_chunk($values, 500) as $chunk) {
                foreach ($this->live($store)->whereIn('scope', $chunk)->get(self::COLUMNS) as $row) {
                    $records[] = $this->record((array) $row);
                }
            }

            return new RecordSet($records);
        });
    }

    /**
     * Store a record one version above the version the writer read.
     *
     * Tries an update at that version, then a restore of a trashed row, then an insert; a row at
     * any other version makes the insert hit the unique index.
     *
     * @throws ConcurrentModification
     */
    public function put(StoreName $store, Record $record): void
    {
        $this->assertWritable($store);

        $this->attempt($store, function () use ($store, $record): void {
            $this->connection()->transaction(function () use ($store, $record): void {
                $now = $record->writtenAt ?? CarbonImmutable::now();
                $columns = $this->columns($store, $record) + ['version' => $record->version, 'updated_at' => $now];
                $expected = $record->version - 1;
                $softDeletes = $this->softDeletes;

                // Versions start at 1, so a first write (expected 0) has no row to update.
                if ($expected > 0 && $this->live($store)->where('scope', $record->scope->value)->where('key', $record->key->value)->where('locale', $record->locale->value)->where('version', $expected)->update($columns) > 0) {
                    return;
                }

                // Restore as a fresh write so nothing from the forgotten row comes back.
                if ($expected > 0 && $softDeletes && $this->trashed($store)->where('scope', $record->scope->value)->where('key', $record->key->value)->where('locale', $record->locale->value)->where('version', $expected)->update($columns + [
                    'deleted_at'  => null,
                    'label'       => null,
                    'description' => null,
                    'meta'        => null,
                    'autoload'    => true,
                    'sort_order'  => 0,
                ]) > 0) {
                    return;
                }

                try {
                    // A savepoint: on Postgres a unique violation would abort the whole transaction.
                    $this->connection()->transaction(fn (): bool => $this->table($store)->insert($columns + $this->tenantColumns() + [
                        'id'         => (string) Str::ulid(),
                        'uid'        => (string) Str::ulid(),
                        'scope'      => $record->scope->value,
                        'key'        => $record->key->value,
                        'locale'     => $record->locale->value,
                        'autoload'   => true,
                        'sort_order' => 0,
                        'created_at' => $now,
                    ]));
                } catch (UniqueConstraintViolationException $exception) {
                    throw $this->conflict($store, $record, $exception);
                }
            });
        });
    }

    /** The version of this identity's trashed row, or 0 when there is none. */
    public function trashedVersion(StoreName $store, Scope $scope, Key $key, Locale $locale): int
    {
        if (! $this->softDeletes) {
            return 0;
        }

        $version = $this->attempt($store, fn (): mixed => $this->identity($this->trashed($store), $scope, $key, $locale)->value('version'));

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * Read one live row under a row lock, for retrying a write after a conflict.
     *
     * A plain re-read under MySQL's REPEATABLE READ would see the old snapshot.
     */
    public function lockedFind(StoreName $store, Scope $scope, Key $key, Locale $locale): ?Record
    {
        return $this->attempt($store, function () use ($store, $scope, $key, $locale): ?Record {
            $row = $this->identity($this->live($store), $scope, $key, $locale)->lockForUpdate()->first(self::COLUMNS);

            return $row === null ? null : $this->record((array) $row);
        });
    }

    /** The row's `uid`, as the history table references it. */
    public function reference(StoreName $store, Scope $scope, Key $key, Locale $locale): ?string
    {
        $reference = $this->attempt($store, fn (): mixed => $this->identity($this->table($store), $scope, $key, $locale)->value('uid'));

        return is_string($reference) ? $reference : null;
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $this->assertWritable($store);

        $this->attempt($store, fn (): int => $this->softDeletes
            ? $this->identity($this->live($store), $scope, $key, $locale)->update(['deleted_at' => CarbonImmutable::now()])
            : $this->identity($this->table($store), $scope, $key, $locale)->delete());
    }

    /** Delete every row of one scope and return how many were deleted. */
    public function flushScope(StoreName $store, Scope $scope): int
    {
        $this->assertWritable($store);

        return $this->attempt($store, fn (): int => $this->softDeletes
            ? $this->live($store)->where('scope', $scope->value)->update(['deleted_at' => CarbonImmutable::now()])
            : $this->table($store)->where('scope', $scope->value)->delete());
    }

    /** Unlink every row of the store, in every tenant and trashed or not, from a category. */
    public function unlinkCategory(StoreName $store, string $category): int
    {
        return $this->attempt($store, fn (): int => $this->connection()->table($this->tableFor($store))
            ->where('category_id', $category)
            ->where('category_store', $store->value)
            ->update(['category_id' => null, 'category_store' => null]));
    }

    /**
     * Every distinct live owner scope of one alias.
     *
     * Uses a range on the binary-collated column, since `_` in a LIKE pattern matches anything.
     *
     * @return list<string>
     */
    public function scopesOf(StoreName $store, string $alias): array
    {
        return $this->attempt($store, fn (): array => array_values(array_filter(
            $this->live($store)
                ->where('scope', '>=', $alias . ':')
                ->where('scope', '<', $alias . ';')
                ->distinct()
                ->orderBy('scope')
                ->pluck('scope')
                ->all(),
            is_string(...),
        )));
    }

    /** @return list<string> */
    public function scopes(StoreName $store): array
    {
        return $this->attempt($store, fn (): array => array_values(array_filter(
            $this->table($store)->distinct()->orderBy('scope')->pluck('scope')->all(),
            is_string(...),
        )));
    }

    /**
     * Rewrite every encrypted payload in the store, across tenants and trashed rows.
     *
     * @param Closure(string): ?string $rewrite returns the new payload, or null when the old one cannot be read
     *
     * @return array{int, int} rewritten and failed counts
     */
    public function rewriteEncrypted(StoreName $store, Closure $rewrite): array
    {
        $this->assertWritable($store);

        return $this->attempt($store, function () use ($store, $rewrite): array {
            $rewritten = 0;
            $failed = 0;

            $this->connection()->table($this->tableFor($store))
                ->where('is_encrypted', true)
                ->whereNotNull('value')
                ->select(['id', 'value'])
                ->chunkById(500, function ($rows) use ($store, $rewrite, &$rewritten, &$failed): void {
                    foreach ($rows as $row) {
                        $row = (array) $row;
                        $new = is_string($row['value'] ?? null) ? $rewrite($row['value']) : null;

                        if ($new === null) {
                            $failed++;

                            continue;
                        }

                        $rewritten += $this->connection()->table($this->tableFor($store))->where('id', $row['id'])->update(['value' => $new]);
                    }
                });

            return [$rewritten, $failed];
        });
    }

    /**
     * Rename an owner alias across every tenant and trashed row, and return how many rows changed.
     *
     * @throws DriverFailure when a renamed row collides with an existing one
     */
    public function rescope(StoreName $store, string $from, string $to): int
    {
        $this->assertWritable($store);

        return $this->attempt($store, function () use ($store, $from, $to): int {
            $changed = 0;

            $this->connection()->table($this->tableFor($store))
                ->where('scope', '>=', $from . ':')
                ->where('scope', '<', $from . ';')
                ->select(['id', 'scope'])
                ->chunkById(500, function ($rows) use ($store, $from, $to, &$changed): void {
                    foreach ($rows as $row) {
                        $row = (array) $row;
                        $scope = $to . substr(is_string($row['scope'] ?? null) ? $row['scope'] : '', strlen($from));
                        $columns = $this->ownerAlias === null ? ['scope' => $scope, 'settable_type' => $to] : ['scope' => $scope];

                        $changed += $this->connection()->table($this->tableFor($store))->where('id', $row['id'])->update($columns);
                    }
                });

            return $changed;
        });
    }

    /**
     * Run a callback in one transaction on this layer's connection.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function transaction(Closure $callback, StoreName $store = new StoreName('settings')): mixed
    {
        // Connect first, so an unreachable database is reported as an outage.
        $connection = $this->attempt($store, function (): Connection {
            $connection = $this->connection();
            $connection->getPdo();

            return $connection;
        });

        return $connection->transaction($callback);
    }

    /**
     * Run a database operation, translating database errors into this package's exceptions.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function attempt(StoreName $store, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (SettingsException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            if (! $this->readyAfterFailure($store, $exception)) {
                throw new DriverUnavailable(sprintf('Store [%s]: the database layer on connection [%s] is unavailable (unreachable, or table [%s] is missing).', $store, $this->connectionName(), $this->tableFor($store)), 0, $exception);
            }

            throw new DriverFailure(sprintf('Store [%s]: the database layer on connection [%s] failed.', $store, $this->connectionName()), 0, $exception);
        }
    }

    /**
     * Whether the layer is still there after a failure.
     *
     * Inside a transaction the SQLSTATE decides, because PostgreSQL refuses every statement in an
     * aborted transaction and a probe would report a healthy table as missing.
     */
    private function readyAfterFailure(StoreName $store, Exception $exception): bool
    {
        if ($this->connection()->transactionLevel() > 0) {
            return ! $this->signalsUnavailable($exception);
        }

        try {
            $probe = ($this->probe)();
            $probe->flush();

            return $probe->isReady([$this->tableFor($store)], $this->connectionName());
        } catch (Exception) {
            return false;
        }
    }

    private function signalsUnavailable(Exception $exception): bool
    {
        $state = '';

        if ($exception instanceof PDOException) {
            $info = $exception->errorInfo[0] ?? null;
            $state = is_string($info) ? $info : (string) $exception->getCode();
        }

        return in_array($state, ['42S02', '42P01'], true)
            || str_starts_with($state, '08')
            || str_contains($exception->getMessage(), 'no such table');
    }

    /** The store's values table, limited to the current tenant. */
    private function table(StoreName $store): Builder
    {
        return $this->scoped($this->connection()->table($this->tableFor($store)));
    }

    /** Rows that are not trashed. */
    private function live(StoreName $store): Builder
    {
        $query = $this->table($store);

        return $this->softDeletes ? $query->whereNull('deleted_at') : $query;
    }

    private function trashed(StoreName $store): Builder
    {
        return $this->table($store)->whereNotNull('deleted_at');
    }

    /** Limit a query to the current tenant in column-mode tenancy. */
    private function scoped(Builder $query): Builder
    {
        return $this->tenantColumn ? $query->where('tenant', $this->tenant()) : $query;
    }

    /** @return array{tenant?: string} the tenant column a new row carries */
    private function tenantColumns(): array
    {
        return $this->tenantColumn ? ['tenant' => $this->tenant()] : [];
    }

    private function identity(Builder $query, Scope $scope, Key $key, Locale $locale): Builder
    {
        return $query->where('scope', $scope->value)->where('key', $key->value)->where('locale', $locale->value);
    }

    /** @return array<string, mixed> the columns every write sets */
    private function columns(StoreName $store, Record $record): array
    {
        $group = $record->group ?? $record->key->group();

        if (strlen($group) > 64) {
            throw new InvalidSettingValue(sprintf('Setting [%s]: the group [%s] is longer than 64 characters; give the definition a shorter group.', $record->key, $group));
        }

        $category = $this->categoryId($store, $record);

        return $this->ownerColumns($store, $record->scope) + [
            'category_id'    => $category,
            'category_store' => $category === null ? null : $store->value,
            'group'          => $group,
            'value'          => $record->payload,
            'type'           => $record->type,
            'is_encrypted'   => $record->encrypted,
            'is_locked'      => $record->locked,
            'is_public'      => $record->public,
        ];
    }

    /**
     * The owner columns of a row: `settable_type` and `settable_id`, or a dedicated store's `owner_id`.
     *
     * @return array<string, int|string|null>
     *
     * @throws OwnerNotAllowed
     */
    private function ownerColumns(StoreName $store, Scope $scope): array
    {
        $owner = $scope->owner();
        [$alias, $id] = $owner instanceof OwnerRef ? [$owner->alias, $owner->id] : [null, null];

        if ($this->ownerAlias === null) {
            return ['settable_type' => $alias, 'settable_id' => $id];
        }

        if ($alias === null || $id === null) {
            throw new OwnerNotAllowed(sprintf('Store [%s] is dedicated to owner [%s] and holds no global values; write those to store [%s].', $store, $this->ownerAlias, $this->fallback));
        }

        if ($alias !== $this->ownerAlias) {
            throw new OwnerNotAllowed(sprintf('Store [%s] is dedicated to owner [%s], not [%s].', $store, $this->ownerAlias, $alias));
        }

        return ['owner_id' => match ($this->ownerKeyType) {
            IdType::Bigint => ctype_digit($id) ? (int) $id : throw new OwnerNotAllowed(sprintf('Store [%s] types owner keys as bigint; [%s] is not one.', $store, $id)),
            IdType::Uuid   => strlen($id) === 36 ? $id : throw new OwnerNotAllowed(sprintf('Store [%s] types owner keys as uuid; [%s] is not one.', $store, $id)),
            IdType::Ulid   => strlen($id) === 26 ? $id : throw new OwnerNotAllowed(sprintf('Store [%s] types owner keys as ulid; [%s] is not one.', $store, $id)),
        }];
    }

    /**
     * The id of the record's category in this store, or null when no store has the slug.
     *
     * @throws CategoryStoreMismatch when the slug belongs to another store only
     */
    private function categoryId(StoreName $store, Record $record): ?string
    {
        if ($record->category === null) {
            return null;
        }

        $categories = $this->scoped($this->connection()->table(new SettingCategory()->getTable()))->where('slug', $record->category);
        $id = (clone $categories)->where('store', $store->value)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $elsewhere = $categories->value('store');

        if (is_string($elsewhere)) {
            throw new CategoryStoreMismatch(sprintf('Setting [%s] names category [%s], which belongs to store [%s], not [%s].', $record->key, $record->category, $elsewhere, $store));
        }

        return null;
    }

    private function conflict(StoreName $store, Record $record, ?Exception $previous = null): ConcurrentModification
    {
        return new ConcurrentModification(sprintf(
            'Setting [%s] in store [%s] for scope [%s] changed since it was read (expected version %d); re-read and retry.',
            $record->key,
            $store,
            $record->scope,
            $record->version - 1,
        ), 0, $previous);
    }

    private function assertWritable(StoreName $store): void
    {
        if (! $this->writable) {
            throw new StoreNotWritable(sprintf('The database layer of store [%s] is read-only.', $store));
        }
    }

    /** @param array<array-key, mixed> $row */
    private function record(array $row): Record
    {
        $scope = $row['scope'] ?? null;
        $key = $row['key'] ?? null;
        $locale = $row['locale'] ?? null;
        $value = $row['value'] ?? null;
        $type = $row['type'] ?? null;
        $group = $row['group'] ?? null;
        $updated = $row['updated_at'] ?? null;
        $version = $row['version'] ?? null;

        if (! is_string($scope) || ! is_string($key) || ! is_string($locale) || ($value !== null && ! is_string($value))
            || ($type !== null && ! is_string($type)) || ($group !== null && ! is_string($group)) || ! is_numeric($version)) {
            throw new DriverFailure(sprintf('A row in the settings table is malformed (scope [%s], key [%s]).', is_string($scope) ? $scope : '?', is_string($key) ? $key : '?'));
        }

        return new Record(
            scope: $this->scope($scope),
            key: new Key($key),
            locale: new Locale($locale),
            payload: $value,
            type: $type,
            encrypted: DatabaseValues::boolean($row['is_encrypted'] ?? false),
            locked: DatabaseValues::boolean($row['is_locked'] ?? false),
            version: (int) $version,
            writtenAt: is_string($updated) ? CarbonImmutable::parse($updated) : null,
            group: $group,
            public: DatabaseValues::boolean($row['is_public'] ?? false),
        );
    }

    private function scope(string $value): Scope
    {
        return Scope::fromString($value) ?? throw new DriverFailure(sprintf('A row in the settings table has a malformed scope [%s].', $value));
    }
}
