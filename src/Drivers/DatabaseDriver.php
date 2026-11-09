<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Closure;
use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
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
 */
final readonly class DatabaseDriver implements DatabaseBacked
{
    private const array COLUMNS = ['scope', 'key', 'locale', 'value', 'type', 'is_encrypted', 'is_locked', 'is_public', 'group', 'version', 'updated_at'];

    /**
     * @param ?string $table the store's configured table (`stores.<name>.table`); null uses the store name
     * @param Closure(): ReadinessProbe $probe resolved only after a database error
     */
    public function __construct(
        private ConnectionResolverInterface $db,
        private ?string $connection,
        private SchemaNames $names,
        private ?string $table,
        private bool $writable,
        private Closure $probe,
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

    /** The connection name this layer uses (the default connection when none is configured). */
    public function connectionName(): string
    {
        return $this->connection ?? $this->db->getDefaultConnection();
    }

    public function tableFor(StoreName $store): string
    {
        return $this->names->store($store, $this->table);
    }

    public function names(): SchemaNames
    {
        return $this->names;
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
                foreach ($this->table($store)->whereIn('scope', $chunk)->get(self::COLUMNS) as $row) {
                    $records[] = $this->record((array) $row);
                }
            }

            return new RecordSet($records);
        });
    }

    /**
     * Store a record whose version is one above the version the writer read: an update checked
     * against that version; failing that, an insert. A row at any other version makes the insert
     * hit the unique index, which is a concurrent change.
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

                // Versions start at 1, so a first write (expected 0) has no row to update.
                if ($expected > 0 && $this->identity($this->table($store), $record->scope, $record->key, $record->locale)->where('version', $expected)->update($columns) > 0) {
                    return;
                }

                // No row at the expected version: insert. If a row exists at another version
                // (a concurrent writer), the unique index refuses the insert.
                try {
                    // A savepoint: on Postgres a unique violation would otherwise abort the
                    // whole surrounding transaction, not just this statement.
                    $this->connection()->transaction(fn (): bool => $this->table($store)->insert($columns + [
                        'scope'      => $record->scope->value,
                        'key'        => $record->key->value,
                        'locale'     => $record->locale->value,
                        'slug'       => $record->key->slug(),
                        'uid'        => (string) Str::ulid(),
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

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $this->assertWritable($store);

        $this->attempt($store, fn (): int => $this->identity($this->table($store), $scope, $key, $locale)->delete());
    }

    /** Remove every row of one scope; returns how many went. */
    public function flushScope(StoreName $store, Scope $scope): int
    {
        $this->assertWritable($store);

        return $this->attempt($store, fn (): int => $this->table($store)->where('scope', $scope->value)->delete());
    }

    /**
     * Every distinct owner scope of one alias in this store (`alias:<id>`), for orphan pruning.
     * A range on the binary-collated column rather than LIKE, whose `_` would match any character.
     *
     * @return list<string>
     */
    public function scopesOf(StoreName $store, string $alias): array
    {
        return $this->attempt($store, fn (): array => array_values(array_filter(
            $this->table($store)
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
            if (! $this->readyAfterFailure($store)) {
                throw new DriverUnavailable(sprintf('Store [%s]: the database layer on connection [%s] is unavailable (unreachable, or table [%s] is missing).', $store, $this->connectionName(), $this->tableFor($store)), 0, $exception);
            }

            throw new DriverFailure(sprintf('Store [%s]: the database layer on connection [%s] failed.', $store, $this->connectionName()), 0, $exception);
        }
    }

    /** Databases return booleans as bool, int or numeric string. */
    private function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /** Asked after a failure, so the answer is fresh; a probe that itself fails means not ready. */
    private function readyAfterFailure(StoreName $store): bool
    {
        try {
            $probe = ($this->probe)();
            $probe->flush();

            return $probe->isReady($this->connectionName(), [$this->tableFor($store)]);
        } catch (Exception) {
            return false;
        }
    }

    private function table(StoreName $store): Builder
    {
        return $this->connection()->table($this->tableFor($store));
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

        [$type, $id] = $this->owner($record->scope);
        $category = $this->categoryId($store, $record);

        return [
            'settable_type'  => $type,
            'settable_id'    => $id,
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

    /** @return array{?string, ?string} the morph alias and id of an owner scope, nulls for global */
    private function owner(Scope $scope): array
    {
        if ($scope->isGlobal()) {
            return [null, null];
        }

        [$alias, $id] = explode(':', $scope->value, 2);

        return [$alias, $id];
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

        $categories = $this->connection()->table($this->names->categories())->where('slug', $record->category);
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
            encrypted: $this->boolean($row['is_encrypted'] ?? false),
            locked: $this->boolean($row['is_locked'] ?? false),
            version: (int) $version,
            writtenAt: is_string($updated) ? CarbonImmutable::parse($updated) : null,
            group: $group,
            public: $this->boolean($row['is_public'] ?? false),
        );
    }

    private function scope(string $value): Scope
    {
        if ($value === Scope::GLOBAL) {
            return Scope::global();
        }

        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            throw new DriverFailure(sprintf('A row in the settings table has a malformed scope [%s].', $value));
        }

        return new OwnerRef($parts[0], $parts[1])->scope();
    }
}
