<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Tests\TestCase;

// Feature tests boot the application; Unit and Arch tests deliberately do not, so a unit
// that quietly relies on the container fails instead of passing.
uses(TestCase::class)->in('Feature');
