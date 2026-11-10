<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Database;

use Closure;
use Throwable;
use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The schema-shape file (`database/settings-schema.php`, or `schema.path`): where `install` and
 * `make-store` record the shape their migrations produce, and where the runtime and the
 * migrations read it back. Application code, so it works on a read-only filesystem once written.
 *
 * Bound `scoped`: the file is read once per request or job, never at boot.
 */
final class SchemaShapeRepository
{
    private ?SchemaShape $current = null;

    /** @param Closure(): SchemaShape $configured the shape the current configuration describes */
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $path,
        private readonly Closure $configured,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return $this->files->isFile($this->path);
    }

    /**
     * The recorded shape, or null when nothing is recorded yet.
     *
     * @throws InvalidConfiguration when the file does not return a valid shape
     */
    public function recorded(): ?SchemaShape
    {
        if (! $this->exists()) {
            return null;
        }

        try {
            $data = $this->files->getRequire($this->path);
        } catch (Throwable $exception) {
            throw new InvalidConfiguration(sprintf('The schema file [%s] could not be read: %s', $this->path, $exception->getMessage()), 0, $exception);
        }

        return is_array($data)
            ? SchemaShape::fromArray($data)
            : throw new InvalidConfiguration(sprintf('The schema file [%s] must return an array.', $this->path));
    }

    /** The shape the current configuration describes. */
    public function configured(): SchemaShape
    {
        return ($this->configured)();
    }

    /** What the runtime and the migrations use: the recorded shape, else the configured one. */
    public function current(): SchemaShape
    {
        return $this->current ??= $this->recorded() ?? $this->configured();
    }

    /**
     * Record the configured shape. Anything already recorded wins: its switches and its stores
     * describe tables that may already exist, so only stores new to the file are added.
     */
    public function record(): SchemaShape
    {
        $recorded = $this->recorded();
        $configured = $this->configured();
        $shape = $recorded ?? $configured;

        foreach ($configured->stores as $store) {
            $shape = $shape->withStore($store);
        }

        $this->write($shape);

        return $shape;
    }

    /** Record one more store (make-store); the rest of the file is kept as it is. */
    public function recordStore(StoreSchema $store): SchemaShape
    {
        $shape = ($this->recorded() ?? $this->configured())->withStore($store);
        $this->write($shape);

        return $shape;
    }

    public function write(SchemaShape $shape): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $this->render($shape));
        $this->current = $shape;
    }

    private function render(SchemaShape $shape): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * laranail/settings: the schema its migrations produced (written by
             * `laranail::settings.install` and `laranail::settings.make-store`). The package reads
             * this file instead of live configuration, so editing config after migrating cannot
             * make it query a column that does not exist. `laranail::settings.doctor` reports any
             * drift between this file, the configuration and the database. Commit it.
             */

            return {$this->export($shape->toArray(), 0)};

            PHP;
    }

    /** var_export with short arrays and stable indentation. */
    private function export(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $lines[] = $indent . var_export($key, true) . ' => ' . $this->export($item, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }
}
