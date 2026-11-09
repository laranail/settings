<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\GeneratedStore;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Generate a new database store (G-3: no runtime DDL): a migration for its values table, copied
 * from the package's stub with the store name filled in, plus the categories and audit tables
 * when the store lives on a connection of its own (each connection keeps its own). Returns the
 * files written and the configuration entry to add by hand.
 *
 * The store's shape (mode, soft deletes, owner table) is recorded in the schema file (§4.0):
 * taken from its configuration entry when it has one, else the default polymorphic shape on the
 * given connection. The file's switches (key type, identity, translations) are kept, so the new
 * table's `category_id` always matches the categories key it points at.
 */
final readonly class MakeStore
{
    private const string STORE_LINE = "private string \$store = 'settings';";

    private const string CONNECTION_LINE = 'private ?string $pinnedConnection = null;';

    public function __construct(
        private Filesystem $files,
        private string $stubs,
        private string $migrations,
        private ?SchemaShapeRepository $shapes = null,
    ) {}

    /**
     * @param list<string> $owners morph aliases the store holds values for
     *
     * @throws InvalidConfiguration when a migration for the store already exists
     */
    public function handle(StoreName $store, ?string $connection = null, array $owners = []): GeneratedStore
    {
        foreach ($owners as $alias) {
            new OwnerRef($alias, 1);
        }

        if ($connection !== null && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $connection) !== 1) {
            throw new InvalidConfiguration(sprintf('Invalid connection name [%s].', $connection));
        }

        $existing = glob(rtrim($this->migrations, '/') . "/*_create_settings_{$store->value}_table.php") ?: [];

        if ($existing !== []) {
            throw new InvalidConfiguration(sprintf('A migration for store [%s] already exists: %s', $store, basename($existing[0])));
        }

        $now = CarbonImmutable::now();
        $pinned = $connection === null ? self::CONNECTION_LINE : sprintf("private ?string \$pinnedConnection = '%s';", $connection);
        $written = [];

        if ($connection !== null) {
            foreach (['create_settings_categories_table', 'create_settings_audit_table'] as $index => $stub) {
                $written[] = $this->write($stub, sprintf('create_settings_%s_%s', $store->value, $index === 0 ? 'categories' : 'audit'), $now->addSeconds($index), [self::CONNECTION_LINE => $pinned]);
            }
        }

        $written[] = $this->write('create_settings_table', "create_settings_{$store->value}_table", $now->addSeconds(2), [
            self::STORE_LINE      => sprintf("private string \$store = '%s';", $store->value),
            self::CONNECTION_LINE => $pinned,
        ]);

        if ($this->shapes instanceof SchemaShapeRepository) {
            $this->shapes->recordStore($this->shapes->configured()->stores[$store->value] ?? new StoreSchema($store, connection: $connection));
        }

        return new GeneratedStore($store, $written, $this->config($store, $connection, $owners));
    }

    /** @param array<string, string> $replacements */
    private function write(string $stub, string $name, CarbonImmutable $at, array $replacements): string
    {
        $source = $this->files->get(rtrim($this->stubs, '/') . "/{$stub}.php.stub");

        foreach ($replacements as $search => $replace) {
            if (! str_contains($source, $search)) {
                throw new InvalidConfiguration(sprintf('The stub [%s] no longer contains [%s]; it cannot be copied for another store.', $stub, $search));
            }

            $source = str_replace($search, $replace, $source);
        }

        $this->files->ensureDirectoryExists($this->migrations);
        $path = rtrim($this->migrations, '/') . '/' . $at->format('Y_m_d_His') . "_{$name}.php";
        $this->files->put($path, $source);

        return $path;
    }

    /** @param list<string> $owners */
    private function config(StoreName $store, ?string $connection, array $owners): string
    {
        $layer = $connection === null ? 'database' : 'database_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($connection));
        $owners = implode(', ', array_map(static fn (string $alias): string => "'{$alias}'", $owners));
        $entry = <<<PHP
            // config/laranail/settings.php → 'stores'
            '{$store->value}' => [
                'layers' => ['{$layer}'],
                'owners' => [{$owners}],
            ],
            PHP;

        if ($connection === null) {
            return $entry;
        }

        return $entry . "\n\n" . <<<PHP
            // config/laranail/settings.php → 'layers'
            '{$layer}' => ['driver' => 'database', 'connection' => '{$connection}'],
            PHP;
    }
}
