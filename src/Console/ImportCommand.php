<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Data\Transfer\ImportRequest;
use Simtabi\Laranail\Settings\Services\Transfer\Importers;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Actions\Transfer\TransferActions;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.import {source}`: import an export file (G-42), or with `--from=<importer>`
 * another package's settings (G-43: `spatie`, `toolkit`, `akaunting`, or one configured in
 * `importers`). A dry run unless `--apply`: it prints what would be created, updated and skipped.
 * `--trusted` lets the file set locks and system categories (G-10). Everything is written through
 * the write pipeline as the system actor, in one transaction, and audited.
 */
final class ImportCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.import
        {source : An export file, or what the importer reads (a connection, connection:table or file)}
        {--store= : The store (default: the default store)}
        {--from= : Read with an importer instead of an export file}
        {--apply : Write the changes (without it, a dry run)}
        {--trusted : Let the file set locks and system categories}';

    protected $description = 'Import settings from an export file or another package (dry run by default)';

    public function handle(TransferActions $transfer, StoreRegistry $stores, Importers $importers, Filesystem $files): int
    {
        $source = $this->argument('source');
        $source = is_string($source) ? $source : '';
        $from = $this->option('from');
        $storeOption = $this->option('store');

        try {
            $store = is_string($storeOption) && $storeOption !== '' ? $stores->get(new StoreName($storeOption)) : $stores->default();

            if (is_string($from) && $from !== '') {
                $document = $importers->get($from)->read($source, $store->name);
            } else {
                if (! $files->isFile($source)) {
                    throw new InvalidImport(sprintf('[%s] is not a file.', $source));
                }

                $document = ExportDocument::fromJson($files->get($source));
            }

            $report = $transfer->import->handle(new ImportRequest(
                $store,
                $document,
                apply: $this->option('apply') === true,
                trusted: $this->option('trusted') === true,
                reason: 'laranail::settings.import',
            ), Actor::system('laranail::settings.import'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($report);

        return self::SUCCESS;
    }

    private function report(ImportReport $report): void
    {
        $this->info(sprintf(
            '%s %d created, %d updated, %d unchanged, %d skipped; %d categor%s created.',
            $report->applied ? 'Imported:' : 'Dry run (add --apply to write):',
            count($report->created),
            count($report->updated),
            count($report->unchanged),
            count($report->skipped),
            count($report->categories),
            count($report->categories) === 1 ? 'y' : 'ies',
        ));

        foreach ([...array_map(static fn (string $id): string => '+ ' . $id, $report->created), ...array_map(static fn (string $id): string => '~ ' . $id, $report->updated)] as $line) {
            $this->line('  ' . $line);
        }

        foreach ($report->skipped as $identity => $reason) {
            $this->line(sprintf('  ! %s skipped: %s', $identity, $reason));
        }

        if ($report->proposed !== []) {
            $this->comment(sprintf('%d setting(s) need approval: change requests %s.', count($report->proposed), implode(', ', $report->proposed)));
        }
    }
}
