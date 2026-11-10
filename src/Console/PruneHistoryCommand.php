<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Actions\PruneHistory;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.prune-history`: apply the history retention policy, `history.keep_days`
 * and `history.keep_versions` (or `--days` / `--versions`). Runs as the system actor and is
 * audited.
 */
final class PruneHistoryCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.prune-history
        {--days= : Keep this many days instead of history.keep_days}
        {--versions= : Keep this many versions of each setting instead of history.keep_versions}';

    protected $description = 'Delete history entries the retention policy no longer keeps';

    public function handle(PruneHistory $prune, Repository $config): int
    {
        $days = $this->number($this->option('days') ?? $config->get('laranail.settings.history.keep_days'));
        $versions = $this->number($this->option('versions') ?? $config->get('laranail.settings.history.keep_versions'));

        if ($days === false || $versions === false) {
            $this->error(__('laranail/settings::settings.console.history_invalid'));

            return self::FAILURE;
        }

        if ($days === null && $versions === null) {
            $this->info(__('laranail/settings::settings.console.history_unconfigured'));

            return self::SUCCESS;
        }

        try {
            $removed = $prune->handle($days, $versions, Actor::system('laranail::settings.prune-history'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(trans_choice('laranail/settings::settings.console.history_pruned', $removed));

        return self::SUCCESS;
    }

    /** A whole number, null when unset, false when it is anything else. */
    private function number(mixed $value): int|false|null
    {
        return match (true) {
            $value === null                          => null,
            is_int($value)                           => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default                                  => false,
        };
    }
}
