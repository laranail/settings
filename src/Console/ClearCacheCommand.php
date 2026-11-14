<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Actions\ClearCache;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Reset the settings cache for every store, or the named ones, across every tenant. Stored values
 * and the application's other cache entries are untouched.
 */
final class ClearCacheCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.clear-cache {--store=* : Reset only these stores (default: every store)}';

    protected $description = 'Reset the cached settings';

    public function handle(ClearCache $clear, StoreRegistry $registry): int
    {
        $actor = Actor::system('laranail::settings.clear-cache');

        try {
            /** @var list<string> $names */
            $names = $this->option('store');
            $cleared = $names === []
                ? $clear->handle(actor: $actor)
                : array_all($names, static fn (string $name): bool => $clear->handle($registry->get(new StoreName($name)), actor: $actor));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($cleared ? __('laranail/settings::settings.console.cache_reset', ['stores' => $names === [] ? __('laranail/settings::settings.console.cache_every_store') : implode(', ', $names)]) : __('laranail/settings::settings.console.cache_off'));

        return self::SUCCESS;
    }
}
