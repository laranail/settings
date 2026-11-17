<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/**
 * What the configurator set, after it ran: each configuration path under `laranail.settings` with
 * its value. `written` is false when the configuration was cached, because the cached values,
 * captured when `config:cache` ran this same configurator, stand instead.
 */
final readonly class AppliedSetup
{
    /** @param array<string, mixed> $values dot paths under `laranail.settings` */
    public function __construct(
        public ConfiguratorRegistration $registration,
        public array $values,
        public bool $written,
    ) {}

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->values);
    }

    public function value(string $path): mixed
    {
        return $this->values[$path] ?? null;
    }
}
