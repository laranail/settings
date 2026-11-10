<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Actions\Transfer\TransferActions;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.diff {path}`: compare a store with an export file (G-42), by identity only,
 * never printing a value. Exits 1 when they differ, so it can gate a deploy.
 */
final class DiffCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.diff
        {path : The export file}
        {--store= : The store (default: the default store)}';

    protected $description = 'Compare a settings store with an export file';

    public function handle(TransferActions $transfer, StoreRegistry $stores, Filesystem $files): int
    {
        $path = $this->argument('path');
        $path = is_string($path) ? $path : '';
        $storeOption = $this->option('store');

        try {
            $store = is_string($storeOption) && $storeOption !== '' ? $stores->get(new StoreName($storeOption)) : $stores->default();

            if (! $files->isFile($path)) {
                throw new InvalidImport(sprintf('[%s] is not a file.', $path));
            }

            $diff = $transfer->diff->handle($store, ExportDocument::fromJson($files->get($path)), Actor::system('laranail::settings.diff'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach (['added' => '+', 'removed' => '-', 'changed' => '~', 'unknown' => '?'] as $bucket => $mark) {
            foreach ($diff->toArray()[$bucket] as $identity) {
                $this->line(sprintf('%s %s', $mark, $identity));
            }
        }

        $this->info($diff->isEmpty()
            ? sprintf('No differences (%d setting(s) equal).', count($diff->unchanged))
            : sprintf('%d to add, %d only in the store, %d changed, %d not comparable (redacted).', count($diff->added), count($diff->removed), count($diff->changed), count($diff->unknown)));

        return $diff->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
