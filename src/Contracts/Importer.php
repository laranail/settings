<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/**
 * Reads another package's settings into an export document for the normal import to write.
 * `$source` is a connection, table or file, as the importer documents.
 */
interface Importer
{
    /** @throws InvalidImport when the source cannot be read or holds something unusable */
    public function read(string $source, StoreName $store): ExportDocument;
}
