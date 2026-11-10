<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Illuminate\Database\Eloquent\Collection;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Models\SettingHistory;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;

/**
 * Re-encrypt every encrypted value, history payload and pending change request under the current
 * key after a key rotation. A payload no key can read is counted as failed and left untouched.
 */
final readonly class ReencryptSettings
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
        private LaravelValueEncrypter $encrypter,
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

            // The history and change requests tables are per connection.
            if (isset($connections[$database->connectionName()])) {
                continue;
            }

            $connections[$database->connectionName()] = true;

            [$rewritten, $failed] = $database->attempt($store->name, fn (): array => $this->rewriteHistory($database->connectionName(), $rewrite));
            $totals['history'] += $rewritten;
            $totals['failed'] += $failed;

            [$rewritten, $failed] = $database->attempt($store->name, fn (): array => $this->rewriteChangeRequests($database->connectionName(), $rewrite));
            $totals['changes'] += $rewritten;
            $totals['failed'] += $failed;
        }

        $this->access->auditor()->record(AuditAction::Reencrypt, $totals['failed'] === 0 ? AuditOutcome::Allowed : AuditOutcome::Failed, $subject, $actor, meta: $totals);

        return $totals;
    }

    /**
     * Rewrite every tenant's encrypted old and new history payloads.
     *
     * @param Closure(string): ?string $rewrite returns null when it cannot read a payload
     *
     * @return array{int, int} rewritten and failed counts
     */
    private function rewriteHistory(string $connection, Closure $rewrite): array
    {
        $rewritten = 0;
        $failed = 0;

        SettingHistory::on($connection)
            ->allTenants()
            ->where(static fn (Builder $query): Builder => $query->where('old_encrypted', true)->orWhere('new_encrypted', true))
            ->chunkById(500, static function (Collection $entries) use ($rewrite, &$rewritten, &$failed): void {
                foreach ($entries as $entry) {
                    $columns = [];

                    foreach (['old', 'new'] as $side) {
                        $payload = $entry->getAttribute($side . '_value');

                        if ($entry->getAttribute($side . '_encrypted') !== true || ! is_string($payload)) {
                            continue;
                        }

                        $new = $rewrite($payload);
                        $new === null ? $failed++ : $columns[$side . '_value'] = $new;
                    }

                    if ($columns !== []) {
                        $entry->update($columns);
                        $rewritten++;
                    }
                }
            });

        return [$rewritten, $failed];
    }

    /**
     * Rewrite every tenant's encrypted proposed payloads. A request's `updated_at` stays, since
     * pruning reads it.
     *
     * @param Closure(string): ?string $rewrite returns null when it cannot read a payload
     *
     * @return array{int, int} rewritten and failed counts
     */
    private function rewriteChangeRequests(string $connection, Closure $rewrite): array
    {
        $rewritten = 0;
        $failed = 0;

        SettingChangeRequest::on($connection)
            ->allTenants()
            ->where('is_encrypted', true)
            ->whereNotNull('payload')
            ->chunkById(500, static function (Collection $requests) use ($rewrite, &$rewritten, &$failed): void {
                foreach ($requests as $request) {
                    $payload = $request->getAttribute('payload');
                    $new = is_string($payload) ? $rewrite($payload) : null;

                    if ($new === null) {
                        $failed++;

                        continue;
                    }

                    $request->timestamps = false;
                    $request->update(['payload' => $new]);
                    $rewritten++;
                }
            });

        return [$rewritten, $failed];
    }
}
