<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Events\HistoryPruned;
use Simtabi\Laranail\Settings\Models\SettingHistory;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Delete history entries older than `history.keep_days` or more than `history.keep_versions`
 * behind their setting's newest, on every connection. Returns the number removed.
 */
final readonly class PruneHistory
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    public function handle(?int $keepDays, ?int $keepVersions, ?Actor $actor = null): int
    {
        if ($keepDays === null && $keepVersions === null) {
            throw new InvalidConfiguration('laranail.settings.history: set keep_days, keep_versions or both before pruning.');
        }

        foreach (['keep_days' => $keepDays, 'keep_versions' => $keepVersions] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidConfiguration(sprintf('laranail.settings.history.%s: [%d] must be at least 1.', $name, $value));
            }
        }

        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::Prune, $subject);

        $cutoff = $keepDays === null ? null : CarbonImmutable::now()->subDays($keepDays);
        $removed = 0;
        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || isset($seen[$database->connectionName()])) {
                continue;
            }

            $seen[$database->connectionName()] = true;
            $removed += $database->attempt($store->name, fn (): int => $this->prune($database->connectionName(), $cutoff, $keepVersions));
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'history', 'keep_days' => $keepDays, 'keep_versions' => $keepVersions, 'removed' => $removed]);
        $this->events->dispatch(new HistoryPruned($removed, $keepDays, $keepVersions));

        return $removed;
    }

    /** Delete every tenant's entries older than `$cutoff` or more than `$keepVersions` behind, and return how many. */
    private function prune(string $connection, ?CarbonImmutable $cutoff, ?int $keepVersions): int
    {
        $removed = $cutoff instanceof CarbonImmutable ? SettingHistory::on($connection)->allTenants()->where('created_at', '<', $cutoff)->toBase()->delete() : 0;

        if ($keepVersions === null) {
            return $removed;
        }

        $columns = [...(ModelContext::hasTenantColumn() ? ['tenant'] : []), 'store', 'scope', 'key', 'locale'];
        $settings = SettingHistory::on($connection)->allTenants()->select($columns)->selectRaw('max(version) as newest')->groupBy($columns)->toBase()->get();

        foreach ($settings as $setting) {
            $setting = (array) $setting;
            $newest = is_numeric($setting['newest'] ?? null) ? (int) $setting['newest'] : 0;

            if ($newest - $keepVersions < 1) {
                continue;
            }

            $query = SettingHistory::on($connection)->allTenants()->where('version', '<=', $newest - $keepVersions);

            foreach ($columns as $column) {
                $query->where($column, $setting[$column] ?? null);
            }

            $removed += $query->toBase()->delete();
        }

        return $removed;
    }
}
