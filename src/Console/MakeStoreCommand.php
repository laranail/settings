<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Actions\MakeStore;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.make-store`: write the migration for a new database store and print the
 * configuration entry to add. Nothing is created at runtime (G-3).
 */
final class MakeStoreCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.make-store
        {store : The store name, [a-z][a-z0-9_]*}
        {--connection= : A database connection of its own (its categories and audit tables are generated too)}
        {--owner=* : A morph alias the store holds values for (repeat for several)}';

    protected $description = 'Generate the migration and configuration for a new settings store';

    public function handle(MakeStore $make): int
    {
        $store = $this->argument('store');
        $connection = $this->option('connection');
        $owners = $this->option('owner');

        try {
            $generated = $make->handle(
                new StoreName(is_string($store) ? $store : ''),
                is_string($connection) && $connection !== '' ? $connection : null,
                is_array($owners) ? array_values(array_filter($owners, is_string(...))) : [],
            );
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($generated->migrations as $path) {
            $this->info('Created ' . $path);
        }

        $this->newLine();
        $this->line('Add this to your configuration, then run the migrations:');
        $this->newLine();
        $this->line($generated->config);

        return self::SUCCESS;
    }
}
