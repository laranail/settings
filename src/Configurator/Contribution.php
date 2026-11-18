<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Simtabi\Laranail\Settings\Enums\ContributionStatus;

/** One registration and what became of it: applied, refused in strict mode, or excluded by the application. */
final readonly class Contribution
{
    /** @param array<string, mixed> $values what it declared, by `laranail.settings` dot path */
    public function __construct(
        public ConfiguratorRegistration $registration,
        public ContributionStatus $status,
        public array $values,
        public ?string $excludedAt = null,
    ) {}
}
