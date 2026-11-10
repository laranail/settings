<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Database;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;

/**
 * Reads and writes the change requests table on one connection, scoped to the current tenant.
 */
final readonly class ChangeRequestRepository
{
    public function __construct(
        private DatabaseDriver $database,
    ) {}

    /**
     * The repository for a store's change requests.
     *
     * @throws ApprovalUnavailable when the store cannot hold change requests
     */
    public static function of(Store $store): self
    {
        $database = $store->database();

        if (! $database instanceof DatabaseDriver || ! $database->shape()->changeRequestsTable) {
            throw new ApprovalUnavailable(sprintf('Store [%s] cannot hold change requests: it needs an active database layer and the change requests table (approvals.table).', $store->name));
        }

        return new self($database);
    }

    /**
     * Build a request from one row; a malformed row raises DriverFailure.
     *
     * @param array<array-key, mixed> $row
     */
    public static function request(array $row): ChangeRequest
    {
        $text = static fn (string $column): ?string => is_string($row[$column] ?? null) ? $row[$column] : null;
        $time = static fn (string $column): ?CarbonImmutable => $text($column) === null ? null : CarbonImmutable::parse($text($column));
        $id = $text('id');
        $operation = ChangeOperation::tryFrom($text('operation') ?? '');
        $status = ChangeStatus::tryFrom($text('status') ?? '');
        $requester = ActorType::tryFrom($text('requested_by_type') ?? '');
        $base = $row['base_version'] ?? null;
        $store = $text('store');
        $scope = $text('scope');
        $key = $text('key');

        if ($id === null || $store === null || $scope === null || $key === null || ! $operation instanceof ChangeOperation || ! $status instanceof ChangeStatus || ! $requester instanceof ActorType || ! is_numeric($base)) {
            throw new DriverFailure(sprintf('Change request row [%s] is malformed.', $id ?? '?'));
        }

        $approvals = $text('approvals') === null ? [] : json_decode($text('approvals'), true);

        return new ChangeRequest(
            id: $id,
            store: $store,
            scope: $scope,
            key: $key,
            locale: $text('locale') ?? '',
            operation: $operation,
            payload: $text('payload'),
            type: $text('type'),
            encrypted: DatabaseValues::boolean($row['is_encrypted'] ?? false),
            baseVersion: (int) $base,
            status: $status,
            requestedByType: $requester,
            requestedByAlias: $text('requested_by_alias'),
            requestedById: $text('requested_by_id'),
            approvals: is_array($approvals) ? array_values(array_filter($approvals, is_string(...))) : [],
            decidedByType: ActorType::tryFrom($text('decided_by_type') ?? ''),
            decidedByAlias: $text('decided_by_alias'),
            decidedById: $text('decided_by_id'),
            decidedAt: $time('decided_at'),
            reason: $text('reason'),
            decisionReason: $text('decision_reason'),
            expiresAt: $time('expires_at'),
            createdAt: $time('created_at'),
        );
    }

    /** @throws UnknownChangeRequest */
    public function get(StoreName $store, string $id): ChangeRequest
    {
        return $this->find($store, $id) ?? throw new UnknownChangeRequest(sprintf('Store [%s] has no change request [%s].', $store, $id));
    }

    public function database(): DatabaseDriver
    {
        return $this->database;
    }

    /**
     * Open a request and return it.
     *
     * @param array{store: string, scope: string, key: string, locale: string, operation: ChangeOperation, payload: ?string, type: ?string, encrypted: bool, base_version: int, reason: ?string, expires_at: ?CarbonImmutable} $request
     */
    public function create(array $request, Actor $requester, CarbonImmutable $now): ChangeRequest
    {
        $id = (string) Str::ulid();
        $store = new StoreName($request['store']);

        $this->database->attempt($store, fn (): bool => $this->table()->insert([
            'id'                 => $id,
            'store'              => $request['store'],
            'scope'              => $request['scope'],
            'key'                => $request['key'],
            'locale'             => $request['locale'],
            'operation'          => $request['operation']->value,
            'payload'            => $request['payload'],
            'type'               => $request['type'],
            'is_encrypted'       => $request['encrypted'],
            'base_version'       => $request['base_version'],
            'status'             => ChangeStatus::Pending->value,
            'requested_by_type'  => $requester->type->value,
            'requested_by_alias' => $requester->alias,
            'requested_by_id'    => $requester->id,
            'approvals'          => null,
            'reason'             => $request['reason'],
            'expires_at'         => $request['expires_at'],
            'created_at'         => $now,
            'updated_at'         => $now,
        ] + $this->database->tenantColumns()));

        return $this->find($store, $id) ?? throw new DriverFailure(sprintf('Change request [%s] was not stored.', $id));
    }

    /** The current tenant's request with this id, or null. */
    public function find(StoreName $store, string $id): ?ChangeRequest
    {
        $row = $this->database->attempt($store, fn (): ?object => $this->database->scoped($this->table())->where('id', $id)->first());

        return $row === null ? null : self::request((array) $row);
    }

    /**
     * The current tenant's requests of a filter, newest first.
     *
     * @return list<ChangeRequest>
     */
    public function list(ChangeFilter $filter, int $limit = 100, ?string $after = null): array
    {
        return $this->database->attempt($filter->store, function () use ($filter, $limit, $after): array {
            $query = $this->query($filter);

            if ($after !== null) {
                $query->where('id', '<', $after);
            }

            return array_values(array_map(static fn (object $row): ChangeRequest => self::request((array) $row), $query->limit($limit)->get()->all()));
        });
    }

    /**
     * Move a request from one of `$from` to a new status; false when someone else decided it first.
     *
     * @param list<ChangeStatus> $from
     * @param list<string>|null $approvals the approvals so far, when they change
     */
    public function transition(ChangeRequest $request, array $from, ChangeStatus $to, ?Actor $decidedBy, ?string $reason, CarbonImmutable $now, ?array $approvals = null): bool
    {
        $columns = ['status' => $to->value, 'updated_at' => $now];

        if ($approvals !== null) {
            $columns['approvals'] = Json::encode($approvals, 'A change request\'s approvals');
        }

        if ($decidedBy instanceof Actor) {
            $columns += [
                'decided_by_type'  => $decidedBy->type->value,
                'decided_by_alias' => $decidedBy->alias,
                'decided_by_id'    => $decidedBy->id,
                'decided_at'       => $now,
                'decision_reason'  => $reason,
            ];
        }

        $statuses = array_map(static fn (ChangeStatus $status): string => $status->value, $from);

        return $this->database->attempt(new StoreName($request->store), function () use ($request, $statuses, $columns): bool {
            $updated = $this->database->scoped($this->table())
                ->where('id', $request->id)
                ->whereIn('status', $statuses)
                ->update($columns);

            if ($updated > 0) {
                return true;
            }

            // MySQL counts changed rows, so an identical update reports 0; that only means a lost
            // race when the target status is not one of the allowed ones.
            if (! in_array($columns['status'], $statuses, true)) {
                return false;
            }

            return $this->database->scoped($this->table())
                ->where('id', $request->id)
                ->where('status', $columns['status'])
                ->exists();
        });
    }

    /**
     * Rewrite every tenant's encrypted proposed payloads.
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

            $this->table()->where('is_encrypted', true)->whereNotNull('payload')->select(['id', 'payload'])->chunkById(500, function ($rows) use ($rewrite, &$rewritten, &$failed): void {
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $new = is_string($row['payload'] ?? null) ? $rewrite($row['payload']) : null;

                    if ($new === null) {
                        $failed++;

                        continue;
                    }

                    $rewritten += $this->table()->where('id', $row['id'])->update(['payload' => $new]);
                }
            });

            return [$rewritten, $failed];
        });
    }

    /** Rename an owner alias in every tenant's requests. */
    public function rescope(StoreName $store, string $from, string $to): int
    {
        return $this->database->attempt($store, function () use ($from, $to): int {
            $changed = 0;

            foreach ($this->table()->where('scope', '>=', $from . ':')->where('scope', '<', $from . ';')->get(['id', 'scope']) as $row) {
                $row = (array) $row;
                $scope = is_string($row['scope'] ?? null) ? $row['scope'] : '';
                $changed += $this->table()->where('id', $row['id'])->update(['scope' => $to . substr($scope, strlen($from))]);
            }

            return $changed;
        });
    }

    /**
     * Expire overdue open requests and delete decided ones older than `$cutoff`, for every tenant.
     *
     * @return array{int, int} expired and deleted counts
     */
    public function sweep(CarbonImmutable $now, ?CarbonImmutable $cutoff, StoreName $store): array
    {
        return $this->database->attempt($store, function () use ($now, $cutoff): array {
            $expired = $this->table()
                ->whereIn('status', [ChangeStatus::Pending->value, ChangeStatus::Approved->value])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->update(['status' => ChangeStatus::Expired->value, 'updated_at' => $now]);

            $deleted = $cutoff instanceof CarbonImmutable
                ? $this->table()
                    ->whereNotIn('status', [ChangeStatus::Pending->value, ChangeStatus::Approved->value])
                    ->where('updated_at', '<', $cutoff)
                    ->delete()
                : 0;

            return [$expired, $deleted];
        });
    }

    /** How many of the current tenant's open requests are past their expiry. */
    public function overdue(CarbonImmutable $now, StoreName $store): int
    {
        return $this->database->attempt($store, fn (): int => $this->database->scoped($this->table())
            ->whereIn('status', [ChangeStatus::Pending->value, ChangeStatus::Approved->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->count());
    }

    private function query(ChangeFilter $filter): Builder
    {
        $query = $this->database->scoped($this->table())->where('store', $filter->store->value);

        foreach (['id' => $filter->id, 'status' => $filter->status?->value, 'scope' => $filter->scope?->value, 'key' => $filter->key?->value] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        return $query->orderByDesc('id');
    }

    private function table(): Builder
    {
        return $this->database->connection()->table($this->database->names()->changeRequests());
    }
}
