<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Data\Transfer\ExportRequest;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Actions\Transfer\StreamExport;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Write a store's settings and categories as JSON, to a file or standard output. Secrets are left
 * out unless `--with-secrets` (ciphertext) or `--decrypt` (plaintext, after confirming the store name).
 */
final class ExportCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.export
        {path? : Where to write the file (default: standard output)}
        {--store= : The store (default: the default store)}
        {--with-secrets : Export encrypted values as their ciphertext}
        {--decrypt : Export encrypted values decrypted}
        {--confirm= : The store name, confirming --decrypt without a prompt}';

    protected $description = 'Export a settings store as JSON';

    public function handle(StoreRegistry $stores, StreamExport $export): int
    {
        $store = $this->option('store');
        $name = is_string($store) && $store !== '' ? $store : null;
        $mode = match (true) {
            $this->option('decrypt') === true      => ExportMode::Plaintext,
            $this->option('with-secrets') === true => ExportMode::Ciphertext,
            default                                => ExportMode::Redacted,
        };

        try {
            $target = $name === null ? $stores->default() : $stores->get(new StoreName($name));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($mode === ExportMode::Plaintext) {
            $confirm = $this->option('confirm');
            $typed = is_string($confirm) ? $confirm : $this->ask(__('laranail/settings::settings.console.export_confirm', ['store' => $target->name->value]));

            if ($typed !== ($name ?? $target->name->value)) {
                $this->error(__('laranail/settings::settings.console.export_mismatch'));

                return self::FAILURE;
            }
        }

        $argument = $this->argument('path');
        $path = is_string($argument) && $argument !== '' ? $argument : null;
        $toFile = $path !== null;
        // Streamed 500 rows at a time; standard output goes through a temporary stream, which
        // spills to disk past 2 MB rather than growing in memory.
        $stream = $path !== null ? @fopen($path, 'wb') : fopen('php://temp', 'w+b');

        if ($stream === false) {
            $this->error(sprintf('Cannot write [%s].', $path ?? 'php://temp'));

            return self::FAILURE;
        }

        try {
            $counts = $export->handle(new ExportRequest($target, $mode), $stream, Actor::system('laranail::settings.export'));

            if (! $toFile) {
                rewind($stream);

                while (! feof($stream)) {
                    $this->output->write((string) fread($stream, 65536));
                }
            }
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            fclose($stream);
        }

        if ($toFile) {
            $this->info(trans_choice('laranail/settings::settings.console.exported', $counts['categories'], ['settings' => $counts['settings'], 'store' => $target->name->value, 'path' => $path]));

            if ($mode === ExportMode::Ciphertext) {
                $this->comment(__('laranail/settings::settings.console.export_ciphertext'));
            }
        }

        return self::SUCCESS;
    }
}
