<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * List a setting's versions, compare two (`--diff=3..5`) or roll back to one (`--rollback=3`).
 * Sensitive values print as `[protected]` unless `--reveal` is given.
 */
final class HistoryCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.history
        {key : The setting key}
        {--store= : The store (default: the default store)}
        {--owner= : An owner scope, alias:id (default: the global value)}
        {--locale= : A locale (default: the unlocalised value)}
        {--diff= : Show the value at two versions, FROM..TO}
        {--rollback= : Roll the setting back to this version}
        {--reveal : Print encrypted and sensitive values in a diff}
        {--limit=20 : How many versions to list}';

    protected $description = 'List, compare or roll back the versions of a setting';

    public function handle(Settings $settings): int
    {
        try {
            $handle = $this->target($settings->asSystem('laranail::settings.history'));
            $key = $this->argument('key');
            $key = is_string($key) ? $key : '';
            $diff = $this->option('diff');
            $rollback = $this->option('rollback');

            if (is_string($rollback)) {
                return $this->rollback($handle, $key, $rollback);
            }

            if (is_string($diff)) {
                return $this->diff($handle, $key, $diff);
            }

            return $this->list($handle, $key);
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function target(Settings $settings): Settings
    {
        $store = $this->option('store');
        $owner = $this->option('owner');
        $locale = $this->option('locale');

        if (is_string($store) && $store !== '') {
            $settings = $settings->store($store);
        }

        if (is_string($owner) && $owner !== '') {
            $settings = $settings->for(Scope::fromString($owner)?->owner() ?? throw new InvalidIdentifier(sprintf('--owner: [%s] is not alias:id.', $owner)));
        }

        return is_string($locale) && $locale !== '' ? $settings->locale($locale) : $settings;
    }

    private function list(Settings $settings, string $key): int
    {
        $limit = $this->option('limit');
        $entries = $settings->history()->key($key)->get(is_string($limit) && ctype_digit($limit) ? (int) $limit : 20);

        if ($entries === []) {
            $this->info(__('laranail/settings::settings.console.history_none', ['key' => $key]));

            return self::SUCCESS;
        }

        $this->table(
            ['Version', 'Operation', 'Scope', 'Locale', 'By', 'Reason', 'At'],
            array_map(static fn (HistoryEntry $entry): array => [
                $entry->version,
                $entry->operation->value,
                $entry->scope,
                $entry->locale === '' ? '-' : $entry->locale,
                $entry->causerType->value . ($entry->causerId === null ? '' : ':' . $entry->causerAlias . ':' . $entry->causerId),
                $entry->reason ?? '',
                $entry->createdAt->toIso8601String(),
            ], $entries),
        );

        return self::SUCCESS;
    }

    private function diff(Settings $settings, string $key, string $range): int
    {
        if (preg_match('/^(\d+)\.\.(\d+)$/', $range, $versions) !== 1) {
            $this->error(__('laranail/settings::settings.console.history_diff_usage'));

            return self::FAILURE;
        }

        $diff = $settings->historyDiff($key, (int) $versions[1], (int) $versions[2]);
        $hidden = $diff->redacted || ($diff->encrypted && $this->option('reveal') !== true);
        $show = static fn (mixed $value, bool $found): string => match (true) {
            $hidden  => '[protected]',
            ! $found => '(no value)',
            default  => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        $this->line(sprintf('v%d: %s', $diff->fromVersion, $show($diff->from, $diff->fromFound)));
        $this->line(sprintf('v%d: %s', $diff->toVersion, $show($diff->to, $diff->toFound)));
        $this->info($diff->changed ? __('laranail/settings::settings.console.history_changed') : __('laranail/settings::settings.console.history_same'));

        return self::SUCCESS;
    }

    private function rollback(Settings $settings, string $key, string $version): int
    {
        if (! ctype_digit($version) || (int) $version < 1) {
            $this->error(__('laranail/settings::settings.console.rollback_usage'));

            return self::FAILURE;
        }

        $new = $settings->rollback($key, (int) $version);
        $this->info(__('laranail/settings::settings.console.rolled_back', ['key' => $key, 'version' => (int) $version, 'new' => $new]));

        return self::SUCCESS;
    }
}
