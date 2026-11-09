<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

/**
 * Positive evidence that the application is not installed yet, the only thing that lets a store
 * in bootstrap mode skip its database layer. The absence of evidence is never evidence: a lost
 * marker or an application without the installer fails closed.
 */
interface BootstrapEvidence
{
    public function holds(): bool;
}
