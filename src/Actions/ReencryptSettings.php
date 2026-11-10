<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Contracts\ValueEncrypter;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\HistoryRepository;
use Simtabi\Laranail\Settings\Services\Database\ChangeRequestRepository;

/**
 * Re-encrypt every encrypted value under the current key after a key rotation (§6): live rows
 * (every tenant's, trashed ones too), the history's old and new values, and pending change
 * requests, on every connection a database store uses. Each payload is decrypted with the current
 * or a previous key (`APP_PREVIOUS_KEYS`) and encrypted again with the current one; one that no
 * key can read is counted as failed and left untouched, so the run never destroys a value. Needs
 * `manage_stores` (step-up verification applies by default) and is audited; the cached values of
 * every store it touched are forgotten.
 */
final readonly class ReencryptSettings
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
        private ValueEncrypter $encrypter,
    ) {}

    /** @return array{values: int, history: int, changes: int, failed: int} */
    public function handle(?Actor $actor = null): array
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageStores, $subject);

        $rewrite = function (string $ciphertext): ?string {
            try {
                return $this->encrypter->encrypt($this->encrypter->decrypt($ciphertext));
            } catch (SettingsException) {
                return null;
            }
        };

        $totals = ['values' => 0, 'history' => 0, 'changes' => 0, 'failed' => 0];
        $connections = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || ! $database->isWritable()) {
                continue;
            }

            [$rewritten, $failed] = $database->rewriteEncrypted($store->name, $rewrite);
            $totals['values'] += $rewritten;
            $totals['failed'] += $failed;
            $store->forgetCachedStore();

            // The history and change requests tables are per connection, so once each.
            if (isset($connections[$database->connectionName()])) {
                continue;
            }

            $connections[$database->connectionName()] = true;

            if ($database->shape()->historyTable) {
                [$rewritten, $failed] = new HistoryRepository($database)->rewriteEncrypted($store->name, $rewrite);
                $totals['history'] += $rewritten;
                $totals['failed'] += $failed;
            }

            if ($database->shape()->changeRequestsTable) {
                [$rewritten, $failed] = new ChangeRequestRepository($database)->rewriteEncrypted($store->name, $rewrite);
                $totals['changes'] += $rewritten;
                $totals['failed'] += $failed;
            }
        }

        $this->access->auditor()->record(AuditAction::Reencrypt, $totals['failed'] === 0 ? AuditOutcome::Allowed : AuditOutcome::Failed, $subject, $actor, meta: $totals);

        return $totals;
    }
}
