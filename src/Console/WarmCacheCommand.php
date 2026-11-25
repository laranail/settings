<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Actions\WarmCache;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Load each cached store's global scope into the cache, so the first request after a deploy does
 * not pay for it.
 */
final class WarmCacheCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.warm {--store=* : Warm only these stores (default: every store)}';

    protected $description = 'Load the global settings of every cached store into the cache';

    public function handle(WarmCache $warm, StoreRegistry $registry): int
    {
        try {
            $stores = $this->stores($registry);
            $rows = [];

            foreach ($stores as $store) {
                if ($store->isCached()) {
                    $rows[] = [$store->name->value, $warm->handle($store, actor: Actor::system('laranail::settings.warm'))];
                }
            }
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info(__('laranail/settings::settings.console.warm_none'));

            return self::SUCCESS;
        }

        $this->table(['Store', 'Records cached'], $rows);

        return self::SUCCESS;
    }

    /** @return list<Store> */
    private function stores(StoreRegistry $registry): array
    {
        /** @var list<string> $names */
        $names = $this->option('store');

        return $names === []
            ? array_values($registry->all())
            : array_map(static fn (string $name): Store => $registry->get(new StoreName($name)), $names);
    }
}
