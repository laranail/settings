<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

/**
 * A store in bootstrap mode skipped its database layer, because the application is being
 * installed and the database is not ready yet. Dispatched once per layer per request, so a
 * skip is reported rather than hidden.
 */
final readonly class LayerSkipped
{
    public function __construct(
        public string $store,
        public string $layer,
        public string $reason,
    ) {}
}
