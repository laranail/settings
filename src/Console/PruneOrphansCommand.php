<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Actions\FindOrphans;
use Simtabi\Laranail\Settings\Actions\PruneOrphans;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.prune-orphans`: remove the settings of owners that no longer exist, which
 * bulk deletes leave behind because they fire no model events.
 */
final class PruneOrphansCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.prune-orphans {--dry-run : List the orphans without removing anything}';

    protected $description = 'Remove settings whose owner no longer exists';

    public function handle(PruneOrphans $prune, FindOrphans $find): int
    {
        try {
            $found = $find->handle();

            foreach ($found['unmapped'] as $alias) {
                $this->warn(__('laranail/settings::settings.console.orphans_unmapped', ['alias' => $alias]));
            }

            $rows = [];

            foreach ($found['orphans'] as $store => $owners) {
                foreach ($owners as $owner) {
                    $rows[] = [$store, $owner->scope()->value];
                }
            }

            if ($rows === []) {
                $this->info(__('laranail/settings::settings.console.orphans_none'));

                return self::SUCCESS;
            }

            $this->table(['Store', 'Scope'], $rows);

            if ($this->option('dry-run') === true) {
                $this->info(__('laranail/settings::settings.console.orphans_dry_run', ['count' => count($rows)]));

                return self::SUCCESS;
            }

            $removed = $prune->handle(Actor::system('laranail::settings.prune-orphans'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('laranail/settings::settings.console.orphans_removed', ['removed' => $removed, 'count' => count($rows)]));

        return self::SUCCESS;
    }
}
