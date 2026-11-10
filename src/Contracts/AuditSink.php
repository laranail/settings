<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\AuditEntry;

/** A destination for audit entries. */
interface AuditSink
{
    public function record(AuditEntry $entry): void;
}
