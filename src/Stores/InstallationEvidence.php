<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Closure;

/**
 * Bootstrap evidence from the operator's flag (`laranail.settings.bootstrap`, set for the install
 * window) or from laranail/installer-headless reporting an install in progress. Its
 * `isInstalled()` is deliberately not used: with its marker missing it probes the live database,
 * which answers "not installed" during an outage.
 */
final readonly class InstallationEvidence
{
    /** @param (Closure(): bool)|null $installing installer-headless' `isInstalling()`, when that package is present */
    public function __construct(
        private bool $flag,
        private ?Closure $installing = null,
    ) {}

    public function holds(): bool
    {
        return $this->flag || ($this->installing instanceof Closure && ($this->installing)() === true);
    }
}
