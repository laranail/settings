<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** One layer's `layers.<name>.*` keys, inside `SettingsSetup::layer()`. */
final readonly class LayerSetup
{
    public function __construct(private SetupValues $values, private string $path) {}

    /** `driver`: database, json, php, config, array, or one registered with the driver factory. */
    public function driver(string $driver): self
    {
        $this->values->set($this->path.'.driver', SetupValues::filled('layer()->driver()', $this->path.'.driver', $driver));

        return $this;
    }

    /** `connection`: the database connection (null for the default). */
    public function connection(?string $connection): self
    {
        $this->values->set($this->path.'.connection', $connection === null ? null : SetupValues::filled('layer()->connection()', $this->path.'.connection', $connection));

        return $this;
    }

    /** `disk`: the filesystem disk a file layer reads and writes. */
    public function disk(string $disk): self
    {
        $this->values->set($this->path.'.disk', SetupValues::filled('layer()->disk()', $this->path.'.disk', $disk));

        return $this;
    }

    /** `path`: the file a file layer reads (a JSON path contains `{store}`). */
    public function path(string $path): self
    {
        $this->values->set($this->path.'.path', SetupValues::filled('layer()->path()', $this->path.'.path', $path));

        return $this;
    }

    /** `writable`: false to make the layer read-only. */
    public function writable(bool $writable = true): self
    {
        $this->values->set($this->path.'.writable', $writable);

        return $this;
    }
}
