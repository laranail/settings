<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use JsonException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Database\Factories\SettingAuditEntryFactory;

/**
 * A row of the append-only audit table: who did what, when and through which channel. Never holds
 * a value.
 *
 * @property string $id
 * @property string $action
 * @property string $outcome
 */
final class SettingAuditEntry extends SettingsModel
{
    /** @use HasFactory<SettingAuditEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes of a row for an entry.
     *
     * @return array<string, mixed>
     */
    public static function attributesOf(AuditEntry $entry): array
    {
        return [
            'id'             => $entry->id,
            'occurred_at'    => $entry->occurredAt,
            'action'         => $entry->action->value,
            'outcome'        => $entry->outcome->value,
            'store'          => $entry->store,
            'scope'          => $entry->scope,
            'key'            => $entry->key,
            'locale'         => $entry->locale,
            'version_from'   => $entry->versionFrom,
            'version_to'     => $entry->versionTo,
            'change_request' => is_string($entry->meta['change_request'] ?? null) ? $entry->meta['change_request'] : null,
            'actor_type'     => $entry->actorType->value,
            'actor_alias'    => $entry->actorAlias,
            'actor_id'       => $entry->actorId,
            'channel'        => $entry->channel->value,
            'ip'             => $entry->ip,
            'user_agent'     => $entry->userAgent,
            'request_id'     => $entry->requestId,
            'reason'         => $entry->reason,
            'meta'           => $entry->meta === [] ? null : $entry->meta,
        ];
    }

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? ModelContext::names()->audit();
    }

    /**
     * This row as an entry.
     *
     * @throws DriverFailure when the row is malformed
     */
    public function toEntry(): AuditEntry
    {
        $row = $this->getAttributes();
        $text = static fn (string $column): ?string => is_string($row[$column] ?? null) ? $row[$column] : null;
        $number = static fn (string $column): ?int => is_numeric($row[$column] ?? null) ? (int) $row[$column] : null;
        $id = $text('id');
        $occurred = $text('occurred_at');
        $action = AuditAction::tryFrom($text('action') ?? '');
        $outcome = AuditOutcome::tryFrom($text('outcome') ?? '');
        $actorType = ActorType::tryFrom($text('actor_type') ?? '');
        $channel = Channel::tryFrom($text('channel') ?? '');

        if ($id === null || $occurred === null || ! $action instanceof AuditAction || ! $outcome instanceof AuditOutcome || ! $actorType instanceof ActorType || ! $channel instanceof Channel) {
            throw new DriverFailure(sprintf('Audit row [%s] is malformed.', $id ?? '?'));
        }

        return new AuditEntry(
            id: $id,
            occurredAt: CarbonImmutable::parse($occurred),
            action: $action,
            outcome: $outcome,
            store: $text('store'),
            scope: $text('scope'),
            key: $text('key'),
            locale: $text('locale'),
            versionFrom: $number('version_from'),
            versionTo: $number('version_to'),
            actorType: $actorType,
            actorAlias: $text('actor_alias'),
            actorId: $text('actor_id'),
            channel: $channel,
            ip: $text('ip'),
            userAgent: $text('user_agent'),
            requestId: $text('request_id'),
            reason: $text('reason'),
            meta: $this->meta($text('meta')),
        );
    }

    /**
     * The rows matching a filter, newest first.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, AuditFilter $filter): Builder
    {
        if ($filter->store instanceof StoreName) {
            $query->where('store', $filter->store->value);
        }

        foreach (['scope' => $filter->scope?->value, 'key' => $filter->key?->value, 'action' => $filter->action?->value, 'outcome' => $filter->outcome?->value] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        if ($filter->since instanceof CarbonImmutable) {
            $query->where('occurred_at', '>=', $filter->since);
        }

        if ($filter->until instanceof CarbonImmutable) {
            $query->where('occurred_at', '<=', $filter->until);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    protected static function newFactory(): SettingAuditEntryFactory
    {
        return SettingAuditEntryFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'meta' => 'array', 'version_from' => 'integer', 'version_to' => 'integer'];
    }

    /** @return array<string, scalar|null> the scalar entries of the stored meta */
    private function meta(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $meta = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $meta[(string) $key] = $value;
            }
        }

        return $meta;
    }
}
