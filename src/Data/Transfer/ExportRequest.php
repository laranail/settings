<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\ExportMode;

/** The input of an export: the store, and how to write its encrypted values (G-10). */
final readonly class ExportRequest
{
    public function __construct(
        public Store $store,
        public ExportMode $mode = ExportMode::Redacted,
    ) {}
}
