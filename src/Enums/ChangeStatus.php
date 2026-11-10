<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * Where a change request stands. `pending` and `approved` (some approvals, more needed) are
 * open; the others are final.
 */
enum ChangeStatus: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Pending')]
    case Pending = 'pending';

    #[Label('Approved, waiting for more approvals')]
    case Approved = 'approved';

    #[Label('Applied')]
    case Applied = 'applied';

    #[Label('Rejected')]
    case Rejected = 'rejected';

    #[Label('Cancelled')]
    case Cancelled = 'cancelled';

    #[Label('Stale: the setting changed after the request was opened')]
    case Stale = 'stale';

    #[Label('Expired')]
    case Expired = 'expired';

    /** Whether the request can still be approved, rejected or cancelled. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
