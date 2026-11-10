<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Data\HistoryFilter;
use Simtabi\Laranail\Settings\Enums\HistoryOperation;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;

/**
 * A row of the value history: one version of a setting, with the payload before and after exactly
 * as the values table stored it, so encrypted values stay encrypted. Rows are looked up by store,
 * scope, key and locale, so a forget followed by a set keeps one trail.
 *
 * @property string $id
 * @property string $store
 * @property string $scope
 * @property string $key
 * @property string $locale
 * @property int $version
 */
final class SettingHistory extends SettingsModel
{
    public const ?string UPDATED_AT = null;

    /**
     * Append one entry; `$before` is null for a first write, `$after` for a forget.
     *
     * @throws DriverFailure when neither row is given
     */
    public static function record(
        ?string $connection,
        StoreName $store,
        HistoryOperation $operation,
        ?Record $before,
        ?Record $after,
        ?string $reference,
        ?Actor $causer,
        ?string $reason,
        CarbonImmutable $at,
    ): self {
        $subject = $after ?? $before ?? throw new DriverFailure('A history entry needs the row before or after the change.');
        $causer ??= Actor::system();

        return self::on($connection)->create([
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
            'version'       => ($after ?? $before)->version,
            'causer_type'   => $causer->type->value,
            'causer_alias'  => $causer->alias,
            'causer_id'     => $causer->id,
            'reason'        => $reason ?? $causer->context->reason,
            'created_at'    => $at,
        ]);
    }

    /** The highest version in a setting's history, or 0. */
    public static function latestVersion(?string $connection, StoreName $store, Scope $scope, Key $key, Locale $locale): int
    {
        $version = self::on($connection)->forSetting($store, $scope, $key, $locale)->max('version');

        return is_numeric($version) ? (int) $version : 0;
    }

    /** The entry that produced this version of a setting's value, or null. */
    public static function entryAt(?string $connection, StoreName $store, Scope $scope, Key $key, Locale $locale, int $version): ?HistoryEntry
    {
        return self::on($connection)
            ->forSetting($store, $scope, $key, $locale)
            ->where('version', $version)
            ->where('operation', '!=', HistoryOperation::Forget->value)
            ->orderByDesc('id')
            ->first()
            ?->toEntry();
    }

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? ModelContext::names()->history();
    }

    /**
     * This row as an entry.
     *
     * @throws DriverFailure when the row is malformed
     */
    public function toEntry(): HistoryEntry
    {
        $row = $this->getAttributes();
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

    /**
     * One setting's trail.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForSetting(Builder $query, StoreName $store, Scope $scope, Key $key, Locale $locale): Builder
    {
        return $query->where('store', $store->value)
            ->where('scope', $scope->value)
            ->where('key', $key->value)
            ->where('locale', $locale->value);
    }

    /**
     * The entries matching a filter, newest version first.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, HistoryFilter $filter): Builder
    {
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

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['old_encrypted' => 'boolean', 'new_encrypted' => 'boolean', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
