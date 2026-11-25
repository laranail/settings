<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Actions\DiagnoseSettings;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorReporter;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Check each database store's readiness, missing columns and the rows the database cannot guard.
 * Exits non-zero on a failure; `--fix` refreshes the definitions table's system rows first.
 */
final class DoctorCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.doctor
        {--json : Emit the report as JSON}
        {--fix : Refresh the definitions table from code and config first}';

    protected $description = 'Check the settings stores: readiness, missing columns and data the database cannot guard';

    public function handle(DiagnoseSettings $diagnose, SyncDefinitions $sync): int
    {
        if ($this->option('fix') === true) {
            try {
                $report = $sync->handle(Actor::system('laranail::settings.doctor'));
                $report['busy']
                    ? $this->warn(__('laranail/settings::settings.console.definitions_busy'))
                    : $this->info(__('laranail/settings::settings.console.definitions_synced', ['synced' => $report['synced'], 'removed' => $report['removed']]));
            } catch (SettingsException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        return DoctorReporter::render($this, $diagnose->handle(), $this->option('json') === true);
    }
}
