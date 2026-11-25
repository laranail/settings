<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Typed;

use Simtabi\Laranail\Settings\Typed\TypedSettings;
use stdClass;

final class BrokenSettings extends TypedSettings
{
    public stdClass $thing;
}
