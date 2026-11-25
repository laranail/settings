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
 * Commits a change and its `allowed` audit entry in one transaction. On failure it rolls back,
 * records a `failed` entry outside the transaction and rethrows the original exception.
 */
final readonly class AuditedTransaction
{
    public function __construct(
        private AccessGuard $access,
    ) {}

    /**
     * @template T
     *
     * @param Closure(): T $work
     * @param (Closure(T): array{from?: ?int, to?: ?int, meta?: array<string, scalar|null>})|null $allowed audit details from the result
     * @param (Closure(T): void)|null $then runs inside the transaction after the audit entry
     * @param array<string, scalar|null> $meta
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
