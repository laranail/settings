<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store's runtime file is about to be promoted into its database layer.
 */
final readonly class StorePromoting implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
    ) {}
}
