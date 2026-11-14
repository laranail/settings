<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DbTools\Schema\SchemaReadinessReport;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

/** A probe that counts flushes. */
function countingProbe(): object
{
    $real = app(SchemaReadinessInterface::class);
    $probe = new class($real) implements SchemaReadinessInterface
    {
        public int $flushes = 0;

        public function __construct(private readonly SchemaReadinessInterface $real) {}

        public function report(array $requiredTables = [], ?string $connection = null): SchemaReadinessReport
        {
            return $this->real->report($requiredTables, $connection);
        }

        public function isReady(array $requiredTables = [], ?string $connection = null): bool
        {
            return true;
        }

        public function whenReady(callable $callback, mixed $default = null, array $requiredTables = [], ?string $connection = null): mixed
        {
            return $callback();
        }

        public function flush(?string $connection = null): void
        {
            $this->flushes++;
        }
    };
    app()->instance(SchemaReadinessInterface::class, $probe);

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
