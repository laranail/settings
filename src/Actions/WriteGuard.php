<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Support\AuditedTransaction;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;

/**
 * What every write action does around its write, in this order: authorize the ability (or
 * check that an actor is not required), refuse settings that need approval, decide whether a
 * locked row may be overridden, run the write, and audit the outcome. Denials are audited by
 * the {@see AccessGuard}; failures and successes here. An allowed write and its audit entry run
 * in one transaction on the store's database connection (when it has one), so a failed audit
 * insert rolls the write back; failures are audited after the rollback. Values never reach the
 * audit.
 */
final readonly class WriteGuard
{
    public function __construct(
        private AccessGuard $access,
        private SettingWriter $writer,
    ) {}

    /**
     * @param bool $respectsLocks whether a locked row stops this write (set and forget, not lock and unlock)
     * @param Closure(bool): array{?int, ?int} $write runs the write (told whether to override a lock); returns [version before, version after]
     */
    public function run(Ability $ability, SettingTarget $target, ?Actor $actor, bool $respectsLocks, Closure $write): void
    {
        $subject = $target->subject();
        $action = AuditAction::fromAbility($ability);

        $this->access->authorize($actor, $ability, $subject);

        if ($subject->requiresApproval) {
            $this->access->auditor()->record($action, AuditOutcome::Failed, $subject, $actor, $target->reason, meta: ['refusal' => 'approval_unavailable']);

            throw new ApprovalUnavailable(sprintf('Setting [%s] in store [%s] requires approval, which needs the database layer (a later release).', $target->key, $target->store->name));
        }

        $override = $respectsLocks && $this->mayOverrideLock($target, $actor);

        // The write and its audit row commit together: an audit failure rolls the write back.
        new AuditedTransaction($this->access)->run(
            $target->store,
            $action,
            $subject,
            $actor,
            static fn (): array => $write($override),
            static fn (array $versions): array => ['from' => $versions[0], 'to' => $versions[1], 'meta' => $override ? ['override_lock' => true] : []],
            reason: $target->reason,
        );
    }

    /**
     * A locked row may be written only by an actor holding `override_lock` on a store whose
     * locks are overridable. Anything else leaves the lock to refuse the write, as before.
     */
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

        // Allowed: enforce it properly, so step-up verification and auditing of a refusal apply.
        $this->access->authorize($actor, Ability::OverrideLock, $subject);

        return true;
    }
}
