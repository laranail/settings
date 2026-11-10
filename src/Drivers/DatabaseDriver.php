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
use Simtabi\Laranail\Settings\Enums\IdentityMode;
use Simtabi\Laranail\Settings\Enums\OwnerKeyType;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Illuminate\Database\UniqueConstraintViolationException;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;

/**
 * Records in a database table, through the query builder (no Eloquent on the hot path).
 *
 * Reads take every scope in one `whereIn('scope', …)` query, so the owner key type never meets
 * the column type (G-26). Writes are version-checked: an update succeeds only against the
 * version the writer read, and an insert runs in a savepoint so a unique violation (a concurrent
 * first write) does not abort the surrounding transaction on Postgres. Either conflict raises
 * {@see ConcurrentModification}. Database errors surface as {@see DriverUnavailable} when the
 * connection or table is gone, {@see DriverFailure} otherwise, never as PDO exceptions.
 *
 * The table's shape (identity columns, key type, owner columns, soft deletes) is read from the
 * recorded {@see SchemaShape}, never from live configuration (§4.0). With soft deletes a forget
 * trashes the row, reads skip trashed rows, and the next write to that identity restores the
 * row through the same version check (so a restore is never read as a conflict and the unique
 * index never collides).
 */
final readonly class DatabaseDriver implements DatabaseBacked
{
    private const array COLUMNS = ['scope', 'key', 'locale', 'value', 'type', 'is_encrypted', 'is_locked', 'is_public', 'group', 'version', 'updated_at'];

    /**
     * @param ?string $table the store's configured table (`stores.<name>.table`); null uses the store name
     * @param Closure(): ReadinessProbe $probe resolved only after a database error
     * @param (Closure(): SchemaShape)|null $shape the recorded schema shape; null is the default shape over $names
     * @param (Closure(): ?string)|null $tenant the current tenant (column-mode tenancy); null is no tenant
     */
    public function __construct(
        private ConnectionResolverInterface $db,
        private ?string $connection,
        private SchemaNames $names,
        private ?string $table,
        private bool $writable,
        private Closure $probe,
        private ?Closure $shape = null,
        private ?Closure $tenant = null,
    ) {}

    /**
     * A new primary key for a table keyed by this type: none for bigint (the database assigns
     * it), a time-ordered UUID or a ULID otherwise.
     *
     * @return array{id?: string}
     */
    public static function newId(IdType $type): array
    {
        return match ($type) {
            IdType::Bigint => [],
            IdType::Uuid   => ['id' => (string) Str::orderedUuid()],
            IdType::Ulid   => ['id' => (string) Str::ulid()],
        };
    }

    /** The recorded schema shape the table was created with. */
    public function shape(): SchemaShape
    {
        $shape = $this->shape instanceof Closure ? ($this->shape)() : null;

        return $shape instanceof SchemaShape ? $shape : new SchemaShape(names: $this->names);
    }

    /** One store's recorded entry: mode, soft deletes, owner table. */
    public function schemaOf(StoreName $store): StoreSchema
    {
        return $this->shape()->store($store);
    }

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

    /** The connection name this layer uses (the default connection when none is configured). */
    public function connectionName(): string
    {
        return $this->connection ?? $this->db->getDefaultConnection();
    }

    public function tableFor(StoreName $store): string
    {
        $shape = $this->shape();

        return $shape->names->store($store, $shape->hasStore($store) ? $shape->store($store)->table : $this->table);
    }

    /**
     * The tenant the rows of this call belong to, in column-mode tenancy: the discriminator's
     * key, or '' (the central rows) outside any tenant. Always '' in the other modes.
     */
    public function tenant(): string
    {
        if (! $this->shape()->tenancy->hasColumn() || ! $this->tenant instanceof Closure) {
            return '';
        }

        $tenant = ($this->tenant)();

        return is_string($tenant) ? $tenant : '';
    }

    /**
     * Limit a query on a values, categories or audit table to the current tenant (column-mode
     * tenancy); any other mode leaves it as it is.
     */
    public function scoped(Builder $query): Builder
    {
        return $this->shape()->tenancy->hasColumn() ? $query->where('tenant', $this->tenant()) : $query;
    }

    /** @return array{tenant?: string} the tenant column a new row carries */
    public function tenantColumns(): array
    {
        return $this->shape()->tenancy->hasColumn() ? ['tenant' => $this->tenant()] : [];
    }

    public function names(): SchemaNames
    {
        return $this->shape()->names;
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
            return ($this->probe)()->isReady($this->connectionName(), [$this->tableFor($store)]);
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
     * Store a record whose version is one above the version the writer read: an update checked
     * against that version; with soft deletes, failing that, a restore of the trashed row at that
     * version; failing that, an insert. A row at any other version makes the insert hit the
     * unique index, which is a concurrent change.
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
                $softDeletes = $this->schemaOf($store)->softDeletes;

                // Versions start at 1, so a first write (expected 0) has no row to update.
                if ($expected > 0 && $this->live($store)->where('scope', $record->scope->value)->where('key', $record->key->value)->where('locale', $record->locale->value)->where('version', $expected)->update($columns) > 0) {
                    return;
                }

                // A trashed row at the expected version: restore it as a fresh write, so no flag,
                // label or category of the forgotten row comes back with it.
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

                // No row at the expected version: insert. If a row exists at another version
                // (a concurrent writer), the unique index refuses the insert.
                try {
                    // A savepoint: on Postgres a unique violation would otherwise abort the
                    // whole surrounding transaction, not just this statement.
                    $this->connection()->transaction(fn (): bool => $this->table($store)->insert($columns + $this->identityColumns($record) + $this->tenantColumns() + [
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

    /**
     * The version of the trashed row of this identity (0 when there is none, or the store keeps
     * no trashed rows), so a write over it continues its version rather than colliding.
     */
    public function trashedVersion(StoreName $store, Scope $scope, Key $key, Locale $locale): int
    {
        if (! $this->schemaOf($store)->softDeletes) {
            return 0;
        }

        $version = $this->attempt($store, fn (): mixed => $this->identity($this->trashed($store), $scope, $key, $locale)->value('version'));

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * The live row of one identity read under a row lock (`SELECT … FOR UPDATE` where the
     * database has one), for retrying a write after a conflict: a plain re-read under MySQL's
     * REPEATABLE READ would see the old snapshot.
     */
    public function lockedFind(StoreName $store, Scope $scope, Key $key, Locale $locale): ?Record
    {
        return $this->attempt($store, function () use ($store, $scope, $key, $locale): ?Record {
            $row = $this->identity($this->live($store), $scope, $key, $locale)->lockForUpdate()->first(self::COLUMNS);

            return $row === null ? null : $this->record((array) $row);
        });
    }

    /**
     * The stable reference of one identity's row (live or trashed) for the history table: its
     * `uid` when the identity mode has one, else its primary key, as a string. Null when there is
     * no row.
     */
    public function reference(StoreName $store, Scope $scope, Key $key, Locale $locale): ?string
    {
        $column = $this->shape()->identity->hasUid() ? 'uid' : 'id';

        $reference = $this->attempt($store, fn (): mixed => $this->identity($this->table($store), $scope, $key, $locale)->value($column));

        return is_int($reference) || is_string($reference) ? (string) $reference : null;
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $this->assertWritable($store);

        $this->attempt($store, fn (): int => $this->schemaOf($store)->softDeletes
            ? $this->identity($this->live($store), $scope, $key, $locale)->update(['deleted_at' => CarbonImmutable::now()])
            : $this->identity($this->table($store), $scope, $key, $locale)->delete());
    }

    /** Remove every row of one scope; returns how many went. */
    public function flushScope(StoreName $store, Scope $scope): int
    {
        $this->assertWritable($store);

        return $this->attempt($store, fn (): int => $this->schemaOf($store)->softDeletes
            ? $this->live($store)->where('scope', $scope->value)->update(['deleted_at' => CarbonImmutable::now()])
            : $this->table($store)->where('scope', $scope->value)->delete());
    }

    /**
     * Every distinct owner scope of one alias in this store (`alias:<id>`) with live rows, for
     * orphan pruning. A range on the binary-collated column rather than LIKE, whose `_` would
     * match any character.
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

    /**
     * Every distinct scope in the store, for diagnostics.
     *
     * @return list<string>
     */
    public function scopes(StoreName $store): array
    {
        return $this->attempt($store, fn (): array => array_values(array_filter(
            $this->table($store)->distinct()->orderBy('scope')->pluck('scope')->all(),
            is_string(...),
        )));
    }

    /**
     * Rewrite every encrypted payload of the store, every tenant's and trashed rows included
     * (re-encryption after a key rotation), in chunks by primary key. `$rewrite` returns the new
     * payload, or null when it could not read the old one (counted as failed, left as it was).
     * Returns [rewritten, failed].
     *
     * @param Closure(string): ?string $rewrite
     *
     * @return array{int, int}
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
     * Rename an owner alias in the store (G-30): every scope `<from>:<id>` becomes `<to>:<id>`,
     * with `settable_type` in a polymorphic store and the per-row slug of `slug_per_row`, for
     * every tenant and trashed rows too, in chunks. Returns how many rows changed. A row whose new
     * identity already exists makes the database refuse the rename (DriverFailure).
     */
    public function rescope(StoreName $store, string $from, string $to): int
    {
        $this->assertWritable($store);

        return $this->attempt($store, function () use ($store, $from, $to): int {
            $changed = 0;
            $polymorphic = ! $this->schemaOf($store)->isDedicated();
            $perRow = $this->shape()->identity === IdentityMode::SlugPerRow;

            $this->connection()->table($this->tableFor($store))
                ->where('scope', '>=', $from . ':')
                ->where('scope', '<', $from . ';')
                ->select(['id', 'scope', 'key', 'locale'])
                ->chunkById(500, function ($rows) use ($store, $from, $to, $polymorphic, $perRow, &$changed): void {
                    foreach ($rows as $row) {
                        $row = (array) $row;
                        $scope = $to . substr(is_string($row['scope'] ?? null) ? $row['scope'] : '', strlen($from));
                        $columns = ['scope' => $scope];

                        if ($polymorphic) {
                            $columns['settable_type'] = $to;
                        }

                        if ($perRow) {
                            $columns['slug'] = sha1($scope . '|' . (is_string($row['key'] ?? null) ? $row['key'] : '') . '|' . (is_string($row['locale'] ?? null) ? $row['locale'] : ''));
                        }

                        $changed += $this->connection()->table($this->tableFor($store))->where('id', $row['id'])->update($columns);
                    }
                });

            return $changed;
        });
    }

    /**
     * Run a callback in one transaction on this layer's connection. The store only names the
     * store in an outage message.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function transaction(Closure $callback, StoreName $store = new StoreName('settings')): mixed
    {
        // Connect first, so an unreachable database is reported like any other outage rather
        // than escaping from BEGIN as a framework exception.
        $connection = $this->attempt($store, function (): Connection {
            $connection = $this->connection();
            $connection->getPdo();

            return $connection;
        });

        return $connection->transaction($callback);
    }

    /**
     * Run a database operation, translating whatever the database throws into this package's
     * exceptions. The package's own exceptions pass through untouched.
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

    /** Asked after a failure, so the answer is fresh; a probe that itself fails means not ready. */
    /**
     * Whether the layer is still there after a failure. Outside a transaction the readiness probe
     * asks the database. Inside one it cannot: PostgreSQL refuses every statement in an aborted
     * transaction, so the probe would report a healthy table as missing. There the SQLSTATE
     * decides instead: a missing table (42S02, 42P01, SQLite's "no such table") or a connection
     * failure (class 08) means unavailable; anything else is a failure of a live layer.
     */
    private function readyAfterFailure(StoreName $store, Exception $exception): bool
    {
        if ($this->connection()->transactionLevel() > 0) {
            return ! $this->signalsUnavailable($exception);
        }

        try {
            $probe = ($this->probe)();
            $probe->flush();

            return $probe->isReady($this->connectionName(), [$this->tableFor($store)]);
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

    /** The store's values table, limited to the current tenant (column-mode tenancy). */
    private function table(StoreName $store): Builder
    {
        return $this->scoped($this->connection()->table($this->tableFor($store)));
    }

    /** Rows that are not trashed (every row, without soft deletes). */
    private function live(StoreName $store): Builder
    {
        $query = $this->table($store);

        return $this->schemaOf($store)->softDeletes ? $query->whereNull('deleted_at') : $query;
    }

    private function trashed(StoreName $store): Builder
    {
        return $this->table($store)->whereNotNull('deleted_at');
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
     * The owner columns of a row, per the store's mode: `settable_type` / `settable_id` (typed per
     * `ids.owner_key`) in a polymorphic store, `owner_id` in a dedicated one, which holds owner
     * rows only.
     *
     * @return array<string, int|string|null>
     *
     * @throws OwnerNotAllowed
     */
    private function ownerColumns(StoreName $store, Scope $scope): array
    {
        $schema = $this->schemaOf($store);
        $owner = $scope->owner();
        [$alias, $id] = $owner instanceof OwnerRef ? [$owner->alias, $owner->id] : [null, null];

        if (! $schema->isDedicated()) {
            $shape = $this->shape();

            return [
                'settable_type' => $alias,
                'settable_id'   => $id === null || $shape->ownerKey === OwnerKeyType::String ? $id : $this->typedKey($store, $id, $shape->ownerKeyType),
            ];
        }

        if ($alias === null || $id === null) {
            throw new OwnerNotAllowed(sprintf('Store [%s] is dedicated to owner [%s] and holds no global values; write those to store [%s].', $store, $schema->ownerAlias, $schema->fallback));
        }

        if ($alias !== $schema->ownerAlias) {
            throw new OwnerNotAllowed(sprintf('Store [%s] is dedicated to owner [%s], not [%s].', $store, $schema->ownerAlias, $alias));
        }

        return ['owner_id' => $this->typedKey($store, $id, $schema->ownerKeyType)];
    }

    /** An owner id as the typed column holds it: an integer for bigint keys. */
    private function typedKey(StoreName $store, string $id, IdType $type): int|string
    {
        return match ($type) {
            IdType::Bigint => ctype_digit($id) ? (int) $id : throw new OwnerNotAllowed(sprintf('Store [%s] types owner keys as bigint; [%s] is not one.', $store, $id)),
            IdType::Uuid   => strlen($id) === 36 ? $id : throw new OwnerNotAllowed(sprintf('Store [%s] types owner keys as uuid; [%s] is not one.', $store, $id)),
            IdType::Ulid   => strlen($id) === 26 ? $id : throw new OwnerNotAllowed(sprintf('Store [%s] types owner keys as ulid; [%s] is not one.', $store, $id)),
        };
    }

    /**
     * The columns only an insert sets: a generated primary key (uuid, ulid) and the identity
     * columns of the identity mode (D-1).
     *
     * @return array<string, string>
     */
    private function identityColumns(Record $record): array
    {
        $shape = $this->shape();
        $columns = self::newId($shape->ids);

        return $columns + match ($shape->identity) {
            IdentityMode::SlugUid    => ['slug' => $record->key->slug(), 'uid' => (string) Str::ulid()],
            IdentityMode::SlugPerRow => ['slug' => sha1($record->scope->value . '|' . $record->key->value . '|' . $record->locale->value)],
            IdentityMode::UidOnly    => ['uid' => (string) Str::ulid()],
        };
    }

    /**
     * The id of the record's category in this store (G-33). A category of that slug existing only
     * in another store is refused; a slug no store has is left unlinked.
     *
     * @throws CategoryStoreMismatch
     */
    private function categoryId(StoreName $store, Record $record): int|string|null
    {
        if ($record->category === null) {
            return null;
        }

        $categories = $this->scoped($this->connection()->table($this->names()->categories()))->where('slug', $record->category);

        if ($this->shape()->categoriesSoftDeletes) {
            $categories->whereNull('deleted_at');
        }

        $id = (clone $categories)->where('store', $store->value)->value('id');

        if (is_int($id) || is_string($id)) {
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
