<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/**
 * How to promote a store: overwrite keys that already have a database row (`force`), and/or
 * replay an archived runtime file (`from`, a path on the runtime layer's disk) instead of the
 * live one, for a database restored from a backup taken before the original promotion.
 */
final readonly class PromotionRequest
{
    public function __construct(
        public bool $force = false,
        public ?string $from = null,
    ) {
        if ($from !== null && ($from === '' || str_contains($from, '..'))) {
            throw new InvalidIdentifier(sprintf('Invalid archive path [%s].', $from));
        }
    }
}
