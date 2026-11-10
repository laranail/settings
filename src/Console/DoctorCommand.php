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
 * `laranail::settings.doctor`: per database store, whether it is ready, whether its tables have
 * drifted from what this release uses, and whether any rows break the rules the database cannot
 * enforce (class-name scopes, unlisted aliases, cross-store categories), and drift between the
 * configuration, the recorded schema file and the database. Exits non-zero on a failure, so it
 * can gate a deploy. `--fix` first refreshes the definitions table's system rows.
 */
final class DoctorCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.doctor
        {--json : Emit the report as JSON}
        {--fix : Refresh the definitions table from code and config first}';

    protected $description = 'Check the settings stores: readiness, schema drift and data the database cannot guard';

    public function handle(DiagnoseSettings $diagnose, SyncDefinitions $sync): int
    {
        if ($this->option('fix') === true) {
            try {
                $report = $sync->handle(Actor::system('laranail::settings.doctor'));
                $this->info(sprintf('Synced %d definition(s); removed %d stale system row(s).', $report['synced'], $report['removed']));
            } catch (SettingsException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        return DoctorReporter::render($this, $diagnose->handle(), $this->option('json') === true);
    }
}
