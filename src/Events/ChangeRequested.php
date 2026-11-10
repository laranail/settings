<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A change request was opened: the write waits for approval. Dispatched after commit; identifiers only, never the proposed value.
 */
final readonly class ChangeRequested implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $id,
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public string $operation,
    ) {}
}
