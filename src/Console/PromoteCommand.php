<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Copy values written to a store's runtime file before install into its database layer, then
 * archive the file. Shipped defaults are never copied, and a second run does nothing.
 */
final class PromoteCommand extends Command
{
    // Symfony rejects the empty segment in `::`; the trait writes the name past that check.
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.promote
        {store? : The store to promote (default: the default store)}
        {--force : Overwrite keys that already have a database row}
        {--from= : Replay an archived runtime file (a path on the runtime disk)}';

    protected $description = 'Promote values written before install from the runtime file into the database';

    public function handle(Settings $settings): int
    {
        $store = $this->argument('store');
        $from = $this->option('from');

        try {
            $handle = is_string($store) ? $settings->store($store) : $settings;
            $report = $handle->asSystem('laranail::settings.promote')->promote(new PromotionRequest(
                force: $this->option('force') === true,
                from: is_string($from) && $from !== '' ? $from : null,
            ));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('laranail/settings::settings.console.promoted', ['store' => $report->store, 'promoted' => count($report->promoted), 'skipped' => count($report->skipped)]));

        if ($report->skipped !== []) {
            $this->table(['Setting (scope|key|locale)', 'Skipped because'], array_map(
                static fn (string $identity, string $reason): array => [$identity, $reason],
                array_keys($report->skipped),
                $report->skipped,
            ));
        }

        if ($report->archive !== null) {
            $this->line(__('laranail/settings::settings.console.promote_archived', ['archive' => $report->archive]));
        }

        return self::SUCCESS;
    }
}
