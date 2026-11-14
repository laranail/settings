<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A category's title changed. */
final readonly class CategoryRenamed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $slug,
    ) {}
}
