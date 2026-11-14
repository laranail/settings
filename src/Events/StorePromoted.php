<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A store's runtime file was promoted into its database layer. */
final readonly class StorePromoted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public int $promoted,
        public int $skipped,
        public ?string $archive,
    ) {}
}
