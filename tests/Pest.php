<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Tests\TestCase;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;

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

/** An unsaved user the access tests act as. */
function user(int $id = 1): TestUser
{
    return TestUser::make($id);
}

/** Define the `laranail-settings.<ability>` gate for each ability, allowing every user. */
function allowGates(Ability ...$abilities): void
{
    foreach ($abilities as $ability) {
        Gate::define($ability->gateName(), static fn (TestUser $user): bool => true);
    }
}

/** Record audit entries in memory for this request. */
function auditInMemory(): InMemoryAuditSink
{
    $sink = new InMemoryAuditSink;
    settings()->configuration()->auditTo($sink);

    return $sink;
}
