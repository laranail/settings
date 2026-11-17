<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

/** A service a provider registered after the configurator's binds. */
final readonly class Greeting
{
    public function __construct(public string $store) {}
}
