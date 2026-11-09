<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\AuditEntry;

/**
 * Where audit entries go. Several sinks can run together (`audit.sinks`).
 */
interface AuditSink
{
    public function record(AuditEntry $entry): void;
}
