<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Database;

use JsonException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * The audit table on one connection (§16.8): every read, write and delete of audit rows goes
 * through here, so the audit sink, the audit query and the retention prune agree on the table,
 * the columns, the tenant filter and the row format. In column-mode tenancy a row carries the
 * current tenant and a query sees the current tenant's rows only; the prune is application-wide.
 */
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseDriver $database,
    ) {}

    /**
     * The audit table a store's entries go to: its own active database layer, else the default
     * store's (entries of a store without a database still land somewhere queryable). Null when
     * neither has one.
     */
    public static function locate(StoreRegistry $stores, ?StoreName $store): ?self
    {
        $database = ($store instanceof StoreName ? $stores->get($store) : $stores->default())->database() ?? $stores->default()->database();

        return $database instanceof DatabaseDriver ? new self($database) : null;
    }

    /**
     * One row as an entry; a row missing a required column raises DriverFailure rather than
     * inventing an entry.
     *
     * @param array<array-key, mixed> $row
     */
    public static function entry(array $row): AuditEntry
    {
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
            meta: self::meta($text('meta')),
        );
    }

    public function database(): DatabaseDriver
    {
        return $this->database;
    }

    /** Write one entry. Its store names the store in an outage message only. */
    public function insert(AuditEntry $entry): void
    {
        $this->database->attempt(new StoreName($entry->store ?? 'audit'), fn (): bool => $this->table()->insert([
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
            'meta'           => $entry->meta === [] ? null : Json::encode($entry->meta, 'An audit entry\'s meta'),
        ] + $this->database->tenantColumns()));
    }

    /** The current tenant's rows matching a filter, newest first. */
    public function query(AuditFilter $filter): Builder
    {
        $query = $this->database->scoped($this->table());

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

    /**
     * Delete every tenant's rows older than the cutoff (retention is one policy for the whole
     * application). The store names the store in an outage message only.
     */
    public function prune(CarbonImmutable $cutoff, StoreName $store): int
    {
        return $this->database->attempt($store, fn (): int => $this->table()->where('occurred_at', '<', $cutoff)->delete());
    }

    /** @return array<string, scalar|null> */
    private static function meta(?string $json): array
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

    private function table(): Builder
    {
        return $this->database->connection()->table($this->database->names()->audit());
    }
}
