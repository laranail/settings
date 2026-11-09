<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Actions\PruneAudit;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.prune-audit`: delete audit rows older than `audit.keep_days` (or `--days`).
 */
final class PruneAuditCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.prune-audit {--days= : Keep this many days instead of audit.keep_days}';

    protected $description = 'Delete audit entries older than the retention period';

    public function handle(PruneAudit $prune, Repository $config): int
    {
        $days = $this->option('days') ?? $config->get('laranail.settings.audit.keep_days', 365);

        if (! is_int($days) && (! is_string($days) || ! ctype_digit($days))) {
            $this->error('The retention period must be a whole number of days.');

            return self::FAILURE;
        }

        try {
            $removed = $prune->handle((int) $days);
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Removed %d audit entr%s older than %d day(s).', $removed, $removed === 1 ? 'y' : 'ies', (int) $days));

        return self::SUCCESS;
    }
}
