<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An approved change request was not applied: its setting changed after the request was opened, so it is stale. Dispatched after commit; identifiers only, never the proposed value.
 */
final readonly class ChangeStale implements ShouldDispatchAfterCommit
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
