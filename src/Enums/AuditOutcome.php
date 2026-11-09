<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * How an audited action ended. Values are never part of an audit entry.
 */
enum AuditOutcome: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Allowed')]
    case Allowed = 'allowed';

    #[Label('Denied')]
    case Denied = 'denied';

    #[Label('Pending approval')]
    case Pending = 'pending';

    #[Label('Failed')]
    case Failed = 'failed';
}
