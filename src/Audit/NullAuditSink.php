<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;

/** Discards every audit entry, for hosts that audit through the package's events instead. */
final readonly class NullAuditSink implements AuditSink
{
    public function record(AuditEntry $entry): void {}
}
