<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Typed;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
}
