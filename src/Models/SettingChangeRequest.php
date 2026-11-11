<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;

/**
 * A row of the change requests table: a write waiting for approval. `payload` is stored as the
 * values table would store it, and `base_version` lets a request whose setting has since changed
 * become stale.
 *
 * @property string $id
 * @property string $store
 * @property string $status
 */
final class SettingChangeRequest extends SettingsModel
{
    /**
     * Open a request and return it.
     *
     * @param array{store: string, scope: string, key: string, locale: string, operation: ChangeOperation, payload: ?string, type: ?string, encrypted: bool, base_version: int, reason: ?string, expires_at: ?CarbonImmutable} $request
     */
    public static function open(?string $connection, array $request, Actor $requester, CarbonImmutable $now): ChangeRequest
    {
        return self::on($connection)->create([
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
            'reason'             => $request['reason'],
            'expires_at'         => $request['expires_at'],
            'created_at'         => $now,
            'updated_at'         => $now,
        ])->refresh()->toRequest();
    }

    /**
     * The request with this id in this store; one opened in another store is unknown here, so a
     * route naming one store can never decide another store's request.
     *
     * @throws UnknownChangeRequest
     */
    public static function get(?string $connection, StoreName $store, string $id): ChangeRequest
    {
        return self::on($connection)->where('store', $store->value)->find($id)?->toRequest()
            ?? throw new UnknownChangeRequest(sprintf('Store [%s] has no change request [%s].', $store, $id));
    }

    /**
     * Move a request from one of `$from` to a new status; false when someone else decided it first.
     *
     * @param list<ChangeStatus> $from
     * @param list<string>|null $approvals the approvals so far, when they change
     */
    public static function transition(?string $connection, ChangeRequest $request, array $from, ChangeStatus $to, ?Actor $decidedBy, ?string $reason, CarbonImmutable $now, ?array $approvals = null): bool
    {
        $columns = ['status' => $to->value, 'updated_at' => $now];

        if ($approvals !== null) {
            $columns['approvals'] = json_encode($approvals, JSON_THROW_ON_ERROR);
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
        $updated = self::on($connection)->whereKey($request->id)->whereIn('status', $statuses)->toBase()->update($columns);

        if ($updated > 0) {
            return true;
        }

        // MySQL counts changed rows, so an identical update reports 0; that only means a lost race
        // when the target status is not one of the allowed ones.
        if (! in_array($to->value, $statuses, true)) {
            return false;
        }

        return self::on($connection)->whereKey($request->id)->where('status', $to->value)->exists();
    }

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? ModelContext::names()->changeRequests();
    }

    /**
     * This row as a request.
     *
     * @throws DriverFailure when the row is malformed
     */
    public function toRequest(): ChangeRequest
    {
        $row = $this->getAttributes();
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

    /**
     * The requests of a filter, newest first.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeMatching(Builder $query, ChangeFilter $filter): Builder
    {
        $query->where('store', $filter->store->value);

        foreach (['id' => $filter->id, 'status' => $filter->status?->value, 'scope' => $filter->scope?->value, 'key' => $filter->key?->value] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        return $query->orderByDesc('id');
    }

    /**
     * Pending and approved requests: those still waiting to be applied.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [ChangeStatus::Pending->value, ChangeStatus::Approved->value]);
    }

    /**
     * Open requests past their expiry.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->open()->whereNotNull('expires_at')->where('expires_at', '<=', $now);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'approvals'    => 'array',
            'is_encrypted' => 'boolean',
            'base_version' => 'integer',
            'decided_at'   => 'immutable_datetime',
            'expires_at'   => 'immutable_datetime',
        ];
    }
}
