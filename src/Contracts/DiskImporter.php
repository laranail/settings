<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/**
 * An importer whose source is a file it can also read from a Laravel filesystem disk (`s3`, `sftp`,
 * ...). `laranail::settings.import --from=<name> --disk=<disk>` calls it; an importer without it
 * refuses `--disk`.
 */
interface DiskImporter extends Importer
{
    /**
     * `$disk` and `$path` may be null for the importer's own default, if it has one.
     *
     * @throws InvalidImport when the disk or file cannot be read or holds something unusable
     */
    public function readFromDisk(?string $disk, ?string $path, StoreName $store): ExportDocument;
}
