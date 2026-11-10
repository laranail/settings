<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A category was moved under another parent, or to the top level when the parent is null. */
final readonly class CategoryMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $slug,
        public ?string $parent,
    ) {}
}
