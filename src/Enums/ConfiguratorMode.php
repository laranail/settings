<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** Which contributors the application lets set the package up; chosen in its configurator. */
enum ConfiguratorMode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Loose')]
    #[Description('Packages, then modules, then the application, each owning what it declares. The default.')]
    case Loose = 'loose';

    #[Label('Strict')]
    #[Description('Only the application\'s configurator applies; packages and modules are listed, reported and refused.')]
    case Strict = 'strict';
}
