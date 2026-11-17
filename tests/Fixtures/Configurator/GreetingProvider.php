<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Support\ServiceProvider;

final class GreetingProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance(Greeting::class, new Greeting('greetings'));
    }
}
