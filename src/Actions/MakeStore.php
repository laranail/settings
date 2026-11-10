<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\GeneratedStore;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;

/**
 * Generate the migration for a new database store: a copy of the package migration with its
 * `$only` (and optionally `$pinnedConnection`) line rewritten, and record the store's shape.
 * Returns the file written and the configuration entry to add.
 */
final readonly class MakeStore
{
    /** The package migration, without its extension. */
    public const string MIGRATION = 'create_laranail_settings_tables';

    private const string ONLY_LINE = 'private ?string $only = null;';

    private const string CONNECTION_LINE = 'private ?string $pinnedConnection = null;';

    /**
     * @param string $migration the package migration file to copy, or a directory holding it
     * @param string $migrations the application's migrations directory
     */
    public function __construct(
        private Filesystem $files,
        private string $migration,
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

        $written = [$this->write("create_settings_{$store->value}_table", CarbonImmutable::now(), [
            self::ONLY_LINE       => sprintf("private ?string \$only = '%s';", $store->value),
            self::CONNECTION_LINE => $connection === null ? self::CONNECTION_LINE : sprintf("private ?string \$pinnedConnection = '%s';", $connection),
        ])];

        if ($this->shapes instanceof SchemaShapeRepository) {
            $this->shapes->recordStore($this->shapes->configured()->stores[$store->value] ?? new StoreSchema($store, connection: $connection));
        }

        return new GeneratedStore($store, $written, $this->config($store, $connection, $owners));
    }

    /** @param array<string, string> $replacements */
    private function write(string $name, CarbonImmutable $at, array $replacements): string
    {
        $file = $this->files->isDirectory($this->migration) ? rtrim($this->migration, '/') . '/' . self::MIGRATION . '.php' : $this->migration;
        $source = $this->files->get($file);

        foreach ($replacements as $search => $replace) {
            if (! str_contains($source, $search)) {
                throw new InvalidConfiguration(sprintf('The migration [%s] no longer contains [%s]; it cannot be copied for another store.', basename($file), $search));
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
