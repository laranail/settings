<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Builds a layer's driver. Built-in names resolve through {@see DriverType} and an exhaustive
 * match (the allow-list); anything else must have been registered with {@see extend()}, as a
 * closure, never a class name.
 */
final class DriverFactory
{
    /** @var array<string, Closure(LayerConfig): Driver> */
    private array $custom = [];

    public function __construct(
        private readonly Disks $disks,
        private readonly Repository $config,
        private readonly ReadOnlyValues $reader,
        private readonly RecordSerializer $serializer,
        private readonly string $basePath,
        private readonly ?ConnectionResolverInterface $db = null,
        private readonly SchemaNames $names = new SchemaNames,
        private readonly ?Closure $probe = null,
    ) {}

    /** @param Closure(LayerConfig): Driver $factory */
    public function extend(string $name, Closure $factory): void
    {
        if (DriverType::tryFrom($name) !== null) {
            throw new InvalidConfiguration(sprintf('Driver [%s] is built in and cannot be replaced.', $name));
        }

        $this->custom[$name] = $factory;
    }

    /**
     * A copy that can also build these custom drivers (runtime configuration uses it, so a
     * driver registered for one request never reaches the shared factory).
     *
     * @param array<string, Closure(LayerConfig): Driver> $drivers
     */
    public function withExtensions(array $drivers): self
    {
        $copy = clone $this;

        foreach ($drivers as $name => $factory) {
            $copy->extend($name, $factory);
        }

        return $copy;
    }

    /** @return list<string> every driver name this factory can build */
    public function names(): array
    {
        return [...array_map(static fn (DriverType $type): string => $type->value, DriverType::cases()), ...array_keys($this->custom)];
    }

    public function make(LayerConfig $layer): Driver
    {
        $type = DriverType::tryFrom($layer->driver);

        if ($type === null) {
            return isset($this->custom[$layer->driver])
                ? ($this->custom[$layer->driver])($layer)
                : throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.driver: unknown driver [%s].', $layer->name, $layer->driver));
        }

        return match ($type) {
            DriverType::Database => $this->database($layer),
            DriverType::Array    => new ArrayDriver($layer->writable),
            DriverType::Json     => $this->json($layer),
            DriverType::Php      => new PhpDriver($this->path($layer, $this->stringOption($layer, 'path')), $this->reader),
            DriverType::Config   => new ConfigDriver($this->config, $this->stringOption($layer, 'prefix'), $this->reader),
        };
    }

    /**
     * The database layer. `connection` is optional (null = the default connection); `table` is
     * set by the store registry from `stores.<name>.table`. Nothing connects here.
     */
    private function database(LayerConfig $layer): DatabaseDriver
    {
        if (! $this->db instanceof ConnectionResolverInterface || ! $this->probe instanceof Closure) {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.driver: the database driver needs the database service.', $layer->name));
        }

        $connection = $layer->option('connection');
        $table = $layer->option('table');

        if (($connection !== null && (! is_string($connection) || $connection === '')) || ($table !== null && ! is_string($table))) {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s: `connection` and `table` must be non-empty strings or null.', $layer->name));
        }

        if (is_string($table)) {
            SchemaNames::assertTable($table, sprintf('laranail.settings.layers.%s.table', $layer->name));
        }

        /** @var Closure(): ReadinessProbe $probe */
        $probe = $this->probe;

        return new DatabaseDriver($this->db, $connection, $this->names, $table, $layer->writable, $probe);
    }

    private function json(LayerConfig $layer): JsonDriver
    {
        $disk = $this->stringOption($layer, 'disk');
        $path = $this->stringOption($layer, 'path');

        // The runtime file may hold settings written before install; never put it where the web
        // server serves files from.
        if ($this->config->get(sprintf('filesystems.disks.%s.visibility', $disk)) === 'public') {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.disk: [%s] is public; use a private disk.', $layer->name, $disk));
        }

        if (! str_contains($path, '{store}')) {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.path: must contain {store} so each store has its own file.', $layer->name));
        }

        return new JsonDriver($this->disks->disk($disk), $path, $layer->writable, $this->serializer);
    }

    private function stringOption(LayerConfig $layer, string $name): string
    {
        $value = $layer->option($name);

        if (! is_string($value) || $value === '') {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.%s: a non-empty string is required.', $layer->name, $name));
        }

        return $value;
    }

    private function path(LayerConfig $layer, string $path): string
    {
        if (str_contains($path, '..')) {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.path: must not contain "..".', $layer->name));
        }

        return str_starts_with($path, '/') ? $path : rtrim($this->basePath, '/') . '/' . $path;
    }
}
