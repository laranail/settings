<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Transfer;

/** The export, import and diff actions (G-42), held together for entry points. */
final readonly class TransferActions
{
    public function __construct(
        public ExportStore $export,
        public ImportStore $import,
        public DiffStore $diff,
    ) {}
}
