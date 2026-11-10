<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A category was moved under another parent (null: to the top level). Dispatched after
 * commit, besides CategorySaved, which cannot say what changed.
 */
final readonly class CategoryMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $slug,
        public ?string $parent,
    ) {}
}
