<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Typed;

use stdClass;
use Simtabi\Laranail\Settings\Typed\TypedSettings;

final class BrokenSettings extends TypedSettings
{
    public stdClass $thing;
}
