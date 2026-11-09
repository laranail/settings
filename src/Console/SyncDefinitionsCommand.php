<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.sync-definitions`: copy code and config definitions into the optional
 * definitions table as read-only system rows (D-6), and remove system rows nothing defines any
 * more. Rows edited at runtime are left alone.
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

        foreach ($report['skipped'] as $store) {
            $this->line(sprintf('Store [%s] skipped: no active database layer, or the definitions table is off.', $store));
        }

        foreach ($report['conflicts'] as $key) {
            $this->warn(sprintf('[%s] is defined in code and edited in the table; code wins, the row was left alone.', $key));
        }

        $this->info(sprintf('Synced %d definition(s); removed %d stale system row(s).', $report['synced'], $report['removed']));

        return self::SUCCESS;
    }
}
