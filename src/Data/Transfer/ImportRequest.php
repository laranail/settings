<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Stores\Store;

/** An import request; a dry run unless `apply`, and `trusted` lets the document set locks. */
final readonly class ImportRequest
{
    public function __construct(
        public Store $store,
        public ExportDocument $document,
        public bool $apply = false,
        public bool $trusted = false,
        public ?string $reason = null,
    ) {}
}
