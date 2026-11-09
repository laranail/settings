<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * The surface a call arrived through. Recorded in the audit trail.
 */
enum Channel: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('PHP API')]
    case Php = 'php';

    #[Label('REST API')]
    case Rest = 'rest';

    #[Label('Console')]
    case Console = 'console';

    #[Label('Queued job')]
    case Queue = 'queue';
}
