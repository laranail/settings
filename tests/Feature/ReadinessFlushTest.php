<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;

/** A probe that counts flushes. */
function countingProbe(): ReadinessProbe
{
    $probe = new class implements ReadinessProbe
    {
        public int $flushes = 0;

        public function isReady(?string $connection, array $tables): bool
        {
            return true;
        }

        public function flush(): void
        {
            $this->flushes++;
        }
    };
    app()->instance(ReadinessProbe::class, $probe);

    return $probe;
}

it('forgets schema readiness when a tenancy package switches tenants, by event name (no dependency)', function (string $event): void {
    $probe = countingProbe();

    Event::dispatch($event);

    expect($probe->flushes)->toBe(1);
})->with([
    'stancl initialized' => 'Stancl\Tenancy\Events\TenancyInitialized',
    'stancl ended'       => 'Stancl\Tenancy\Events\TenancyEnded',
    'spatie current'     => 'Spatie\Multitenancy\Events\MadeTenantCurrentEvent',
    'spatie forgot'      => 'Spatie\Multitenancy\Events\ForgotCurrentTenantEvent',
]);

it('does not flush on events nobody configured', function (): void {
    $probe = countingProbe();

    Event::dispatch('App\Events\SomethingElse');

    expect($probe->flushes)->toBe(0);
});
