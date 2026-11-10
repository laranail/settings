<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Events\ChangeStale;
use Simtabi\Laranail\Settings\Data\ApprovalPolicy;
use Simtabi\Laranail\Settings\Data\ChangeDecision;
use Simtabi\Laranail\Settings\Events\ChangeApplied;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Events\ChangeApproved;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;

/**
 * Approve a change request; needs `approve` on its setting. With enough approvals the change is
 * written against the request's base version, and a setting that moved on since makes the
 * request `stale` instead. Returns the request as it now stands.
 */
final readonly class ApproveChange
{
    public function __construct(
        private AccessGuard $access,
        private SettingWriter $writer,
        private ValuePipeline $pipeline,
        private Dispatcher $events,
        private Definitions $definitions,
        private ApprovalPolicy $policy = new ApprovalPolicy,
    ) {}

    /**
     * @throws AccessDenied
     * @throws ChangeRequestClosed
     */
    public function handle(ChangeDecision $decision, ?Actor $actor = null): ChangeRequest
    {
        $store = $decision->store;
        $database = $store->changeRequestsDatabase();
        $request = $database->attempt($store->name, static fn (): ChangeRequest => SettingChangeRequest::get($database->connectionName(), $store->name, $decision->id));
        $key = new Key($request->key);
        $definition = $this->definitions->find($store, $key);
        $subject = Subject::of($store->name, $key, new Locale($request->locale), $request->owner(), $definition);
        $now = CarbonImmutable::now();

        $this->access->authorize($actor, Ability::Approve, $subject);
        $this->assertOpen($database, $request, $now);

        if ($actor instanceof Actor && ! $this->policy->allowSelf && $request->isRequestedBy($actor)) {
            $this->access->auditor()->record(AuditAction::Approve, AuditOutcome::Denied, $subject, $actor, $decision->reason, meta: ['change_request' => $request->id, 'refusal' => 'self_approval']);

            throw AccessDenied::for(Ability::Approve, $subject, 'the requester cannot approve their own change request');
        }

        $approvals = array_values(array_unique([...$request->approvals, ChangeRequest::actorKey($actor ?? Actor::system())]));
        $remaining = max(0, $this->policy->minApprovals - count($approvals));

        if ($remaining > 0) {
            return $this->record($database, $request, $subject, $actor, $decision->reason, $approvals, $remaining, $now);
        }

        try {
            $version = $store->transaction(function () use ($database, $request, $store, $key, $definition, $subject, $actor, $decision, $approvals, $now): int {
                if (! $database->attempt(new StoreName($request->store), static fn (): bool => SettingChangeRequest::transition($database->connectionName(), $request, [ChangeStatus::Pending, ChangeStatus::Approved], ChangeStatus::Applied, $actor ?? Actor::system(), $decision->reason, $now, $approvals))) {
                    throw new ChangeRequestClosed(sprintf('Change request [%s] was decided by somebody else first.', $request->id));
                }

                $version = $this->apply($request, $store, $key, $definition, $actor, $decision->reason);

                $this->access->auditor()->record(AuditAction::Approve, AuditOutcome::Allowed, $subject, $actor, $decision->reason, $request->baseVersion > 0 ? $request->baseVersion : null, $version, [
                    'change_request' => $request->id,
                    'operation'      => $request->operation->value,
                ]);

                return $version;
            });
        } catch (ConcurrentModification) {
            // The setting moved on since the request was opened: never overwrite it.
            $database->attempt(new StoreName($request->store), static fn (): bool => SettingChangeRequest::transition($database->connectionName(), $request, [ChangeStatus::Pending, ChangeStatus::Approved], ChangeStatus::Stale, $actor ?? Actor::system(), $decision->reason, $now, $approvals));
            $this->access->auditor()->record(AuditAction::Approve, AuditOutcome::Failed, $subject, $actor, $decision->reason, meta: ['change_request' => $request->id, 'refusal' => 'stale']);
            $this->events->dispatch(new ChangeStale($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value));

            return $database->attempt($store->name, static fn (): ChangeRequest => SettingChangeRequest::get($database->connectionName(), $store->name, $request->id));
        }

        $this->events->dispatch(new ChangeApproved($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value));
        $this->events->dispatch(new ChangeApplied($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value, $version));

        return $database->attempt($store->name, static fn (): ChangeRequest => SettingChangeRequest::get($database->connectionName(), $store->name, $request->id));
    }

    /** @param list<string> $approvals */
    private function record(DatabaseDriver $database, ChangeRequest $request, Subject $subject, ?Actor $actor, ?string $reason, array $approvals, int $remaining, CarbonImmutable $now): ChangeRequest
    {
        if (! $database->attempt(new StoreName($request->store), static fn (): bool => SettingChangeRequest::transition($database->connectionName(), $request, [ChangeStatus::Pending, ChangeStatus::Approved], ChangeStatus::Approved, null, null, $now, $approvals))) {
            throw new ChangeRequestClosed(sprintf('Change request [%s] was decided by somebody else first.', $request->id));
        }

        $this->access->auditor()->record(AuditAction::Approve, AuditOutcome::Pending, $subject, $actor, $reason, meta: ['change_request' => $request->id, 'remaining' => $remaining]);
        $this->events->dispatch(new ChangeApproved($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value, $remaining));

        return $database->attempt($subject->store, static fn (): ChangeRequest => SettingChangeRequest::get($database->connectionName(), $subject->store, $request->id));
    }

    private function assertOpen(DatabaseDriver $database, ChangeRequest $request, CarbonImmutable $now): void
    {
        if ($request->status->isOpen() && $request->isExpired($now)) {
            $database->attempt(new StoreName($request->store), static fn (): bool => SettingChangeRequest::transition($database->connectionName(), $request, [ChangeStatus::Pending, ChangeStatus::Approved], ChangeStatus::Expired, null, null, $now));

            throw new ChangeRequestClosed(sprintf('Change request [%s] expired at %s.', $request->id, $request->expiresAt?->toIso8601String()));
        }

        if (! $request->status->isOpen()) {
            throw new ChangeRequestClosed(sprintf('Change request [%s] is %s and can no longer be decided.', $request->id, $request->status->value));
        }
    }

    /**
     * Write the requested change, checked against the base version; returns the new version.
     *
     * @throws ConcurrentModification when the setting is no longer at the base version
     */
    private function apply(ChangeRequest $request, Store $store, Key $key, ?Definition $definition, ?Actor $actor, ?string $reason): int
    {
        $locale = new Locale($request->locale);
        $owner = $request->owner();
        $scope = Scope::of($owner);
        $writer = $this->writer->causedBy($actor, $reason ?? 'change request ' . $request->id);

        if ($request->operation === ChangeOperation::Set) {
            $value = $this->pipeline->fromRecord(new Record($scope, $key, $locale, $request->payload, $request->type, $request->encrypted), $definition);

            return $writer->setExpecting($store, $key, $locale, $owner, $definition, $value, $request->baseVersion)->version;
        }

        $current = $store->writableLayer()->driver->load($store->name, [$scope])->find($scope, $key, $locale)->version ?? 0;

        if ($current !== $request->baseVersion) {
            throw new ConcurrentModification(sprintf('Setting [%s] in store [%s] for scope [%s] is at version %d, not %d.', $key, $store->name, $scope, $current, $request->baseVersion));
        }

        return match ($request->operation) {
            ChangeOperation::Forget => $writer->forget($store, $key, $locale, $owner, $definition)->version ?? $current,
            ChangeOperation::Lock   => $writer->lock($store, $key, $locale, $owner, $definition)->version,
            default                 => $writer->unlock($store, $key, $locale, $owner, $definition)->version,
        };
    }
}
