<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.export {path?}`: write a store's settings and categories as JSON (G-42), to
 * a file or standard output. Encrypted values are left out unless `--with-secrets` (their
 * ciphertext, importable only under the same APP_KEY or one in APP_PREVIOUS_KEYS) or `--decrypt`
 * (plaintext, which asks you to type the store name, or takes it as `--confirm=<store>`). Runs as
 * the system actor and is audited; never logs a value.
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

    public function handle(Settings $settings, Filesystem $files): int
    {
        $store = $this->option('store');
        $handle = $settings->asSystem('laranail::settings.export');
        $handle = is_string($store) && $store !== '' ? $handle->store($store) : $handle;
        $name = is_string($store) && $store !== '' ? $store : null;
        $mode = match (true) {
            $this->option('decrypt') === true      => ExportMode::Plaintext,
            $this->option('with-secrets') === true => ExportMode::Ciphertext,
            default                                => ExportMode::Redacted,
        };

        try {
            $document = $handle->export($mode);
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($mode === ExportMode::Plaintext) {
            $confirm = $this->option('confirm');
            $typed = is_string($confirm) ? $confirm : $this->ask(__('laranail/settings::settings.console.export_confirm', ['store' => $document->store]));

            if ($typed !== ($name ?? $document->store)) {
                $this->error(__('laranail/settings::settings.console.export_mismatch'));

                return self::FAILURE;
            }
        }

        $path = $this->argument('path');

        if (is_string($path) && $path !== '') {
            $files->put($path, $document->toJson());
            $this->info(trans_choice('laranail/settings::settings.console.exported', count($document->categories), ['settings' => count($document->settings), 'store' => $document->store, 'path' => $path]));

            if ($mode === ExportMode::Ciphertext) {
                $this->comment(__('laranail/settings::settings.console.export_ciphertext'));
            }

            return self::SUCCESS;
        }

        $this->output->write($document->toJson());

        return self::SUCCESS;
    }
}
