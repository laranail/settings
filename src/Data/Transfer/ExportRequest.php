<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\ExportMode;

/** An export request: the store and how to write its encrypted values. */
final readonly class ExportRequest
{
    public function __construct(
        public Store $store,
        public ExportMode $mode = ExportMode::Redacted,
    ) {}
}
