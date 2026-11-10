<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

/** A store in bootstrap mode skipped its database layer. Dispatched once per layer per request. */
final readonly class LayerSkipped
{
    public function __construct(
        public string $store,
        public string $layer,
        public string $reason,
    ) {}
}
