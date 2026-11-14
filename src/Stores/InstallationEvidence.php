<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Closure;

/**
 * Whether an install is in progress, from the `laranail.settings.bootstrap` flag or installer-headless.
 *
 * Installer-headless' `isInstalled()` is not used: it probes the database, which reads as "not
 * installed" during an outage.
 */
final readonly class InstallationEvidence
{
    /** @param (Closure(): bool)|null $installing installer-headless' `isInstalling()` */
    public function __construct(
        private bool $flag,
        private ?Closure $installing = null,
    ) {}

    public function holds(): bool
    {
        return $this->flag || ($this->installing instanceof Closure && ($this->installing)() === true);
    }
}
