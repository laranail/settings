<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Actions\DiagnoseSettings;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorReporter;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.doctor`: per database store, whether it is ready, whether its tables have
 * drifted from what this release uses, and whether any rows break the rules the database cannot
 * enforce (class-name scopes, unlisted aliases, cross-store categories). Exits non-zero on a
 * failure, so it can gate a deploy.
 */
final class DoctorCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.doctor {--json : Emit the report as JSON}';

    protected $description = 'Check the settings stores: readiness, schema drift and data the database cannot guard';

    public function handle(DiagnoseSettings $diagnose): int
    {
        return DoctorReporter::render($this, $diagnose->handle(), $this->option('json') === true);
    }
}
