<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Benchmarks;

use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;

/**
 * A settings owner with one parent, without a model or a table: the benchmark measures the
 * settings read, not Eloquent.
 */
final readonly class BenchOwner implements Settable
{
    public function __construct(
        private int $id,
        private int $team,
    ) {}

    public function settingsOwner(): OwnerRef
    {
        return new OwnerRef('owner', (string) $this->id);
    }

    /** @return list<OwnerRef> */
    public function settingsParents(): array
    {
        return [new OwnerRef('team', (string) $this->team)];
    }
}
