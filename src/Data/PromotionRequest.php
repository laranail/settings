<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/** Promotion options: `force` overwrites existing rows; `from` replays an archived runtime file. */
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
