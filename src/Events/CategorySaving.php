<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A category is about to be created, renamed or moved. */
final readonly class CategorySaving implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $slug,
    ) {}
}
