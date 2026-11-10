<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A required layer is unreachable (the database is down, or its table is missing) and no bootstrap evidence lets the store skip it. Never a fallback to defaults.
 */
final class DriverUnavailable extends SettingsException
{
    public const int STATUS = 503;

    public const string TYPE = 'unavailable';
}
