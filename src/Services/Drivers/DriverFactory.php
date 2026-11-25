<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Drivers;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Drivers\PhpDriver;
use Simtabi\Laranail\Settings\Enums\TenancyMode;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Drivers\ArrayDriver;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Drivers\ConfigDriver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Drivers\ReadOnlyValues;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

/**
 * Builds a layer's driver from a built-in {@see DriverType} or a closure registered with {@see extend()}.
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
        private readonly ?Closure $probe = null,
        private readonly ?Closure $tenant = null,
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
     * A copy that can also build these custom drivers, leaving the shared factory unchanged.
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

    /** Build the database layer without connecting. */
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

        /** @var Closure(): SchemaReadinessInterface $probe */
        $probe = $this->probe;

        /** @var (Closure(): ?string)|null $tenant */
        $tenant = $this->tenant;
        $owner = $layer->option('owner');

        return new DatabaseDriver(
            db: $this->db,
            connection: $connection,
            table: $table,
            writable: $layer->writable,
            probe: $probe,
            tenantColumn: TenancyMode::fromConfig($this->config->get('laranail.settings.tenancy.mode'))->hasColumn(),
            tenant: $tenant,
            softDeletes: $layer->option('soft_deletes') === true,
            ownerAlias: is_array($owner) && is_string($owner['alias'] ?? null) ? $owner['alias'] : null,
            ownerKeyType: is_array($owner) ? $this->ownerKeyType($owner['type'] ?? null) : IdType::Bigint,
            fallback: is_array($owner) && is_string($owner['fallback'] ?? null) ? $owner['fallback'] : null,
        );
    }

    /** A dedicated store's owner key type; unset, it follows `laranail.db-tools.id_type`. */
    private function ownerKeyType(mixed $type): IdType
    {
        $type ??= $this->config->get('laranail.db-tools.id_type');

        return (is_string($type) ? IdType::parse($type) : null) ?? IdType::Bigint;
    }

    private function json(LayerConfig $layer): JsonDriver
    {
        $disk = $this->stringOption($layer, 'disk');
        $path = $this->stringOption($layer, 'path');

        // The runtime file may hold settings written before install; never serve it publicly.
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
