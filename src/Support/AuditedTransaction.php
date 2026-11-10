<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Closure;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * The one way a change and its audit entry are committed: the work and an `allowed` audit entry
 * run in one transaction on the store's database connection (when it has one), so a failed audit
 * insert rolls the change back; on any SettingsException the transaction rolls back, a `failed`
 * entry is recorded outside it, and the original exception is rethrown (if recording the failure
 * fails too, the original still wins). Authorization happens before this, in the caller.
 *
 * Used by every write action, every category change and promotion, so all three audit alike.
 */
final readonly class AuditedTransaction
{
    public function __construct(
        private AccessGuard $access,
    ) {}

    /**
     * @template T
     *
     * @param Closure(): T $work the change
     * @param (Closure(T): array{from?: ?int, to?: ?int, meta?: array<string, scalar|null>})|null $allowed audit details from the change's result
     * @param (Closure(T): void)|null $then runs after the allowed entry, still inside the transaction (events)
     * @param array<string, scalar|null> $meta meta on both the allowed and the failed entry
     *
     * @return T
     */
    public function run(
        Store $store,
        AuditAction $action,
        Subject $subject,
        ?Actor $actor,
        Closure $work,
        ?Closure $allowed = null,
        ?Closure $then = null,
        array $meta = [],
        ?string $reason = null,
    ): mixed {
        try {
            return $store->transaction(function () use ($work, $allowed, $then, $action, $subject, $actor, $meta, $reason): mixed {
                $result = $work();
                $details = $allowed instanceof Closure ? $allowed($result) : [];

                $this->access->auditor()->record(
                    $action,
                    AuditOutcome::Allowed,
                    $subject,
                    $actor,
                    $reason,
                    $details['from'] ?? null,
                    $details['to'] ?? null,
                    $meta + ($details['meta'] ?? []),
                );

                if ($then instanceof Closure) {
                    $then($result);
                }

                return $result;
            });
        } catch (SettingsException $exception) {
            try {
                $this->access->auditor()->record($action, AuditOutcome::Failed, $subject, $actor, $reason, meta: $meta + ['error' => $exception::class]);
            } catch (SettingsException) {
                // The caller is told about the original failure below; nothing else to do here.
            }

            throw $exception;
        }
    }
}
