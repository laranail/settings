<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Stores\Store;

/**
 * The input of an import: the store, the document, and how to apply it. Nothing is written
 * unless `apply` is set (the default is a dry run). `trusted` lets the document set locks and
 * system categories (G-10); otherwise both are ignored.
 */
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
