<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** An export file (or importer source) is not a document this release can read. */
final class InvalidImport extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'invalid-import';
}
