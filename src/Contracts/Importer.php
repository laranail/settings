<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/**
 * Reads another package's settings into an export document (G-43), which the normal import then
 * writes through the write pipeline. An importer only reads; it never writes, and it needs none of
 * the package it reads from. `$source` is what that package keeps (a connection, a table, a
 * file), in the form the importer documents.
 */
interface Importer
{
    /** @throws InvalidImport when the source cannot be read or holds something unusable */
    public function read(string $source, StoreName $store): ExportDocument;
}
