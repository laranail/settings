<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** One configured layer: its name, driver and options. */
final readonly class LayerConfig
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $name,
        public string $driver,
        public bool $writable,
        public array $options = [],
    ) {}

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
