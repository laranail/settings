<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * The kind of {@see \Simtabi\Laranail\Settings\Data\Decision} an authorizer returns. `abstain`
 * means "no opinion": the next authorizer in a chain decides, and if none does,
 * `authorization.undefined` applies.
 */
enum Verdict: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Allow')]
    case Allow = 'allow';

    #[Label('Deny')]
    case Deny = 'deny';

    #[Label('Requires verification')]
    case RequiresVerification = 'requires_verification';

    #[Label('Requires approval')]
    case RequiresApproval = 'requires_approval';

    #[Label('Abstain')]
    case Abstain = 'abstain';
}
