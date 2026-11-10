<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Data\ChangeProposal;
use Simtabi\Laranail\Settings\Support\AuditedTransaction;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange;

/**
 * Wraps every write: authorize, open a change request when approval is needed, decide lock
 * overrides, run the write and audit it in one transaction.
 */
final readonly class WriteGuard
{
    public function __construct(
        private AccessGuard $access,
        private SettingWriter $writer,
        private ProposeChange $proposer,
    ) {}

    /**
     * @param bool $respectsLocks whether a locked row stops this write (set and forget, not lock and unlock)
     * @param Closure(bool): array{?int, ?int} $write runs the write (told whether to override a lock); returns [version before, version after]
     * @param ?ChangeProposal $proposal the change request to open instead, when the setting needs approval
     *
     * @return ?ChangeRequest the change request opened instead of writing, or null when the write ran
     */
    public function run(Ability $ability, SettingTarget $target, ?Actor $actor, bool $respectsLocks, Closure $write, ?ChangeProposal $proposal = null): ?ChangeRequest
    {
        $subject = $target->subject();
        $action = AuditAction::fromAbility($ability);

        $this->access->authorize($actor, $ability, $subject);

        if ($subject->requiresApproval) {
            if ($proposal instanceof ChangeProposal && $target->store->acceptsChangeRequests()) {
                return $this->proposer->handle($proposal, $actor);
            }

            $this->access->auditor()->record($action, AuditOutcome::Failed, $subject, $actor, $target->reason, meta: ['refusal' => 'approval_unavailable']);

            throw new ApprovalUnavailable($proposal instanceof ChangeProposal
                ? sprintf('Setting [%s] in store [%s] requires approval, and the store cannot hold change requests (it needs an active database layer and the change requests table).', $target->key, $target->store->name)
                : sprintf('Setting [%s] in store [%s] requires approval; propose the change instead of making it directly.', $target->key, $target->store->name));
        }

        $override = $respectsLocks && $this->mayOverrideLock($target, $actor);

        new AuditedTransaction($this->access)->run(
            $target->store,
            $action,
            $subject,
            $actor,
            static fn (): array => $write($override),
            static fn (array $versions): array => ['from' => $versions[0], 'to' => $versions[1], 'meta' => $override ? ['override_lock' => true] : []],
            reason: $target->reason,
        );

        return null;
    }

    /** Whether the actor may write over a lock: `override_lock` on a store with overridable locks. */
    private function mayOverrideLock(SettingTarget $target, ?Actor $actor): bool
    {
        if (! $actor instanceof Actor || $actor->isSystem() || ! $target->store->locksOverridable) {
            return false;
        }

        if (! $this->writer->isLocked($target->store, $target->key, $target->locale, $target->owner)) {
            return false;
        }

        $subject = $target->subject();

        if (! $this->access->allows($actor, Ability::OverrideLock, $subject)) {
            return false;
        }

        // Enforce again so step-up verification and refusal auditing apply.
        $this->access->authorize($actor, Ability::OverrideLock, $subject);

        return true;
    }
}
