<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Database;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Data\HistoryFilter;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Enums\HistoryOperation;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * Reads and writes the value history table on one connection.
 *
 * Payloads are kept as the values table stored them, so encrypted values stay encrypted. Rows
 * are looked up by store, scope, key and locale, so a forget followed by a set keeps one trail.
 */
final readonly class HistoryRepository
{
    public function __construct(
        private DatabaseDriver $database,
    ) {}

    /** The history table of a store, or null when the store has none. */
    public static function locate(StoreRegistry $stores, StoreName $store): ?self
    {
        $database = $stores->get($store)->database();

        return $database instanceof DatabaseDriver && $database->shape()->historyTable ? new self($database) : null;
    }

    /**
     * Build an entry from one row; a malformed row raises DriverFailure.
     *
     * @param array<array-key, mixed> $row
     */
    public static function entry(array $row): HistoryEntry
    {
        $text = static fn (string $column): ?string => is_string($row[$column] ?? null) ? $row[$column] : null;
        $id = $text('id');
        $store = $text('store');
        $scope = $text('scope');
        $key = $text('key');
        $created = $text('created_at');
        $operation = HistoryOperation::tryFrom($text('operation') ?? '');
        $causer = ActorType::tryFrom($text('causer_type') ?? '');
        $version = $row['version'] ?? null;

        if ($id === null || $store === null || $scope === null || $key === null || $created === null || ! $operation instanceof HistoryOperation || ! $causer instanceof ActorType || ! is_numeric($version)) {
            throw new DriverFailure(sprintf('History row [%s] is malformed.', $id ?? '?'));
        }

        return new HistoryEntry(
            id: $id,
            store: $store,
            settingRef: $text('setting_ref'),
            scope: $scope,
            key: $key,
            locale: $text('locale') ?? '',
            operation: $operation,
            oldValue: $text('old_value'),
            newValue: $text('new_value'),
            oldType: $text('old_type'),
            newType: $text('new_type'),
            oldEncrypted: DatabaseValues::boolean($row['old_encrypted'] ?? false),
            newEncrypted: DatabaseValues::boolean($row['new_encrypted'] ?? false),
            version: (int) $version,
            causerType: $causer,
            causerAlias: $text('causer_alias'),
            causerId: $text('causer_id'),
            reason: $text('reason'),
            createdAt: CarbonImmutable::parse($created),
        );
    }

    public function database(): DatabaseDriver
    {
        return $this->database;
    }

    /** Append one entry in the caller's transaction; `$before` is null for a first write, `$after` for a forget. */
    public function record(
        StoreName $store,
        HistoryOperation $operation,
        ?Record $before,
        ?Record $after,
        int $version,
        ?string $reference,
        ?Actor $causer,
        ?string $reason,
        CarbonImmutable $at,
    ): void {
        $subject = $after ?? $before ?? throw new DriverFailure('A history entry needs the row before or after the change.');
        $causer ??= Actor::system();

        $this->database->attempt($store, fn (): bool => $this->table()->insert([
            'id'            => (string) Str::ulid(),
            'store'         => $store->value,
            'setting_ref'   => $reference,
            'scope'         => $subject->scope->value,
            'key'           => $subject->key->value,
            'locale'        => $subject->locale->value,
            'operation'     => $operation->value,
            'old_value'     => $before?->payload,
            'new_value'     => $after?->payload,
            'old_type'      => $before?->type,
            'new_type'      => $after?->type,
            'old_encrypted' => $before instanceof Record && $before->encrypted,
            'new_encrypted' => $after instanceof Record && $after->encrypted,
            'version'       => $version,
            'causer_type'   => $causer->type->value,
            'causer_alias'  => $causer->alias,
            'causer_id'     => $causer->id,
            'reason'        => $reason ?? $causer->context->reason,
            'created_at'    => $at,
        ] + $this->database->tenantColumns()));
    }

    /** The highest version in this identity's history, or 0. */
    public function latestVersion(StoreName $store, Scope $scope, Key $key, Locale $locale): int
    {
        $version = $this->database->attempt($store, fn (): mixed => $this->identity($store, $scope, $key, $locale)->max('version'));

        return is_numeric($version) ? (int) $version : 0;
    }

    /** The entry that produced this version of the value, or null. */
    public function find(StoreName $store, Scope $scope, Key $key, Locale $locale, int $version): ?HistoryEntry
    {
        $row = $this->database->attempt($store, fn (): ?object => $this->identity($store, $scope, $key, $locale)
            ->where('version', $version)
            ->where('operation', '!=', HistoryOperation::Forget->value)
            ->orderByDesc('id')
            ->first());

        return $row === null ? null : self::entry((array) $row);
    }

    /** The current tenant's entries matching a filter, newest version first. */
    public function query(HistoryFilter $filter): Builder
    {
        $query = $this->database->scoped($this->table());

        foreach (['store' => $filter->store?->value, 'scope' => $filter->scope?->value, 'key' => $filter->key?->value, 'locale' => $filter->locale?->value] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        if ($filter->since instanceof CarbonImmutable) {
            $query->where('created_at', '>=', $filter->since);
        }

        if ($filter->until instanceof CarbonImmutable) {
            $query->where('created_at', '<=', $filter->until);
        }

        if ($filter->fromVersion !== null) {
            $query->where('version', '>=', $filter->fromVersion);
        }

        if ($filter->toVersion !== null) {
            $query->where('version', '<=', $filter->toVersion);
        }

        return $query->orderByDesc('version')->orderByDesc('id');
    }

    /** Delete entries older than `$cutoff` or more than `$keepVersions` behind, and return how many. */
    public function prune(?CarbonImmutable $cutoff, ?int $keepVersions, StoreName $store): int
    {
        return $this->database->attempt($store, function () use ($cutoff, $keepVersions): int {
            $removed = $cutoff instanceof CarbonImmutable ? $this->table()->where('created_at', '<', $cutoff)->delete() : 0;

            if ($keepVersions === null) {
                return $removed;
            }

            $tenant = $this->database->shape()->tenancy->hasColumn() ? ['tenant'] : [];
            $groups = $this->table()
                ->select([...$tenant, 'store', 'scope', 'key', 'locale'])
                ->selectRaw('max(version) as newest')
                ->groupBy([...$tenant, 'store', 'scope', 'key', 'locale'])
                ->get();

            foreach ($groups as $group) {
                $group = (array) $group;
                $newest = is_numeric($group['newest'] ?? null) ? (int) $group['newest'] : 0;

                if ($newest - $keepVersions < 1) {
                    continue;
                }

                $query = $this->table()->where('version', '<=', $newest - $keepVersions);

                foreach ([...$tenant, 'store', 'scope', 'key', 'locale'] as $column) {
                    $query->where($column, $group[$column] ?? null);
                }

                $removed += $query->delete();
            }

            return $removed;
        });
    }

    /**
     * Rewrite every encrypted old and new payload in the history, for every tenant.
     *
     * @param Closure(string): ?string $rewrite returns null when it cannot read a payload
     *
     * @return array{int, int} rewritten and failed counts
     */
    public function rewriteEncrypted(StoreName $store, Closure $rewrite): array
    {
        return $this->database->attempt($store, function () use ($rewrite): array {
            $rewritten = 0;
            $failed = 0;

            $this->table()
                ->where(static fn (Builder $query): Builder => $query->where('old_encrypted', true)->orWhere('new_encrypted', true))
                ->select(['id', 'old_value', 'new_value', 'old_encrypted', 'new_encrypted'])
                ->chunkById(500, function ($rows) use ($rewrite, &$rewritten, &$failed): void {
                    foreach ($rows as $row) {
                        $row = (array) $row;
                        $columns = [];

                        foreach (['old', 'new'] as $side) {
                            $payload = $row[$side . '_value'] ?? null;

                            if (! DatabaseValues::boolean($row[$side . '_encrypted'] ?? false) || ! is_string($payload)) {
                                continue;
                            }

                            $new = $rewrite($payload);
                            $new === null ? $failed++ : $columns[$side . '_value'] = $new;
                        }

                        if ($columns !== []) {
                            $this->table()->where('id', $row['id'])->update($columns);
                            $rewritten++;
                        }
                    }
                });

            return [$rewritten, $failed];
        });
    }

    /** Rename an owner alias in every tenant's history and return how many entries changed. */
    public function rescope(StoreName $store, string $from, string $to): int
    {
        return $this->database->attempt($store, function () use ($store, $from, $to): int {
            $changed = 0;

            $this->table()
                ->where('store', $store->value)
                ->where('scope', '>=', $from . ':')
                ->where('scope', '<', $from . ';')
                ->select(['id', 'scope'])
                ->chunkById(500, function ($rows) use ($from, $to, &$changed): void {
                    foreach ($rows as $row) {
                        $row = (array) $row;
                        $scope = is_string($row['scope'] ?? null) ? $row['scope'] : '';
                        $changed += $this->table()->where('id', $row['id'])->update(['scope' => $to . substr($scope, strlen($from))]);
                    }
                });

            return $changed;
        });
    }

    private function identity(StoreName $store, Scope $scope, Key $key, Locale $locale): Builder
    {
        return $this->database->scoped($this->table())
            ->where('store', $store->value)
            ->where('scope', $scope->value)
            ->where('key', $key->value)
            ->where('locale', $locale->value);
    }

    private function table(): Builder
    {
        return $this->database->connection()->table($this->database->names()->history());
    }
}
