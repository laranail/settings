<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Copy code and config definitions into the definitions table as read-only system rows, and remove
 * system rows nothing defines any more. Rows edited at runtime are left alone.
 */
final class SyncDefinitionsCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.sync-definitions';

    protected $description = 'Copy code and config definitions into the settings definitions table';

    public function handle(SyncDefinitions $sync): int
    {
        try {
            $report = $sync->handle(Actor::system('laranail::settings.sync-definitions'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report['busy']) {
            $this->warn(__('laranail/settings::settings.console.definitions_busy'));

            return self::SUCCESS;
        }

        foreach ($report['skipped'] as $store) {
            $this->line(__('laranail/settings::settings.console.definitions_skipped', ['store' => $store]));
        }

        foreach ($report['conflicts'] as $key) {
            $this->warn(__('laranail/settings::settings.console.definitions_conflict', ['key' => $key]));
        }

        $this->info(__('laranail/settings::settings.console.definitions_synced', ['synced' => $report['synced'], 'removed' => $report['removed']]));

        return self::SUCCESS;
    }
}
