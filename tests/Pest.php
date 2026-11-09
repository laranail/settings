<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Tests\TestCase;
use Simtabi\Laranail\Settings\Contracts\Settings;

// Feature tests boot the application; Unit and Arch tests deliberately do not, so a unit
// that quietly relies on the container fails instead of passing.
uses(TestCase::class)->in('Feature');

function settings(): Settings
{
    return app(Settings::class);
}

/**
 * Write a shipped-defaults PHP file and point the `shipped` layer at it.
 *
 * @param array<string, mixed> $values
 */
function shipDefaults(array $values): void
{
    $path = sys_get_temp_dir() . '/laranail-settings-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
    config()->set('laranail.settings.layers.shipped.path', $path);
}
