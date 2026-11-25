<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;

/**
 * What the settings setup came to, once every registration ran: each `laranail.settings` path with
 * its value and the registration that owns it, the mode, and what became of each registration.
 * `written` is false when the configuration was cached, because the cached values, captured when
 * `config:cache` ran these same registrations, stand instead.
 */
final readonly class AppliedSetup
{
    /**
     * @param  array<string, mixed>  $values  dot paths under `laranail.settings`
     * @param  array<string, ConfiguratorRegistration>  $owners  each applied path's owner
     * @param  list<Contribution>  $contributions  every registration, in the order it was applied
     */
    public function __construct(
        public ?ConfiguratorRegistration $registration,
        public array $values,
        public bool $written,
        public ConfiguratorMode $mode = ConfiguratorMode::Loose,
        public array $owners = [],
        public array $contributions = [],
    ) {}

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->values);
    }

    public function value(string $path): mixed
    {
        return $this->values[$path] ?? null;
    }

    /** The identity that owns a path (`app`, a package or a module name), or null when none set it. */
    public function owner(string $path): ?string
    {
        return ($this->owners[$path] ?? null)?->identity;
    }

    /** @return list<Contribution> */
    public function contributions(?ConfiguratorScope $scope = null): array
    {
        return array_values(array_filter($this->contributions, static fn (Contribution $contribution): bool => ! $scope instanceof ConfiguratorScope || $contribution->registration->scope === $scope));
    }
}
