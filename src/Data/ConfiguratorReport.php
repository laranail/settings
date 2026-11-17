<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Configurator\Contribution;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;

/** What `laranail::settings.configurator` shows: who set the package up, and where each key's value came from. */
final readonly class ConfiguratorReport
{
    /**
     * @param list<ConfiguratorRegistration> $registrations every registration, in registration order
     * @param list<Contribution> $contributions what became of each, in the order applied (empty before the setup ran)
     * @param list<ConfigurationValue> $entries
     * @param array<string, string> $leftover each `LARANAIL_SETTINGS_*` variable still set => its replacement
     */
    public function __construct(
        public ?ConfiguratorRegistration $application,
        public array $registrations,
        public array $contributions,
        public ConfiguratorMode $mode,
        public bool $configCached,
        public array $entries,
        public array $leftover,
    ) {}
}
