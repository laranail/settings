<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** Who registered a piece of the settings setup, in the order they are applied. */
enum ConfiguratorScope: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Package')]
    #[Description('A Composer package, identified by its package name.')]
    case Package = 'package';

    #[Label('Module')]
    #[Description('An application module, identified by its module name.')]
    case Module = 'module';

    #[Label('Application')]
    #[Description('The application\'s one configurator, applied last.')]
    case Application = 'application';
}
