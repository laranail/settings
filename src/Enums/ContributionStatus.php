<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;

/** What became of one registered configurator or contributor. */
enum ContributionStatus: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Applied')]
    #[Description('What it declared was written into the configuration.')]
    case Applied = 'applied';

    #[Label('Refused (strict)')]
    #[Description('The application runs in strict mode, so nothing it declared was applied.')]
    case Refused = 'refused';

    #[Label('Excluded by the application')]
    #[Description('The application left it out with exclude(); its names are free for the application.')]
    case Excluded = 'excluded';
}
