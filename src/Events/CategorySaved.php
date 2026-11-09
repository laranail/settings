<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A category was created, renamed or moved (after the transaction commits).
 */
final readonly class CategorySaved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $slug,
    ) {}
}
