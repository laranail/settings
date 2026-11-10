<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use JsonException;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Data\AuditPage;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;

/**
 * Read the audit trail (§16.8): needs `view_audit` when an actor is present (a trusted call
 * without one reads freely unless an actor is required). Entries come from the audit table on
 * the connection of the filtered store (the default store when none is named), newest first.
 * Entries never carry a value, so there is nothing to redact.
 */
final readonly class QueryAudit
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
    ) {}

    /** @return list<AuditEntry> */
    public function get(AuditFilter $filter, ?Actor $actor = null, ?int $limit = null): array
    {
        $query = $this->query($filter, $actor);

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return array_values(array_map(fn (object $row): AuditEntry => $this->entry((array) $row), $query->get()->all()));
    }

    /** @return LazyCollection<int, AuditEntry> streamed in chunks, for large trails */
    public function cursor(AuditFilter $filter, ?Actor $actor = null): LazyCollection
    {
        return $this->query($filter, $actor)->cursor()->map(fn (object $row): AuditEntry => $this->entry((array) $row));
    }

    /** One page, newest first; pass the page's `nextCursor` back for the next one. */
    public function paginate(AuditFilter $filter, ?Actor $actor = null, int $perPage = 50, ?string $cursor = null): AuditPage
    {
        $page = $this->query($filter, $actor)->cursorPaginate(max(1, $perPage), ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        return new AuditPage(
            array_values(array_map(fn (mixed $row): AuditEntry => $this->entry(is_object($row) ? (array) $row : []), $page->items())),
            max(1, $perPage),
            $page->nextCursor()?->encode(),
            $page->previousCursor()?->encode(),
        );
    }

    private function query(AuditFilter $filter, ?Actor $actor): Builder
    {
        $store = $filter->store instanceof StoreName ? $this->stores->get($filter->store) : $this->stores->default();

        $this->access->authorize($actor, Ability::ViewAudit, new Subject(
            $store->name,
            key: $filter->key,
            owner: $filter->scope?->owner(),
        ));

        $database = $store->database() ?? $this->stores->default()->database();

        if (! $database instanceof DatabaseDriver) {
            throw new DriverUnavailable(sprintf('Store [%s] has no active database layer, so its audit trail went to the log and cannot be queried.', $store->name));
        }

        // In column-mode tenancy a tenant reads its own trail only.
        $query = $database->scoped($database->connection()->table($database->names()->audit()));

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

    /** @param array<array-key, mixed> $row */
    private function entry(array $row): AuditEntry
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
            meta: $this->meta($text('meta')),
        );
    }

    /** @return array<string, scalar|null> */
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
