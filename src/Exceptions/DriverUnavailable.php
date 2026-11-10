<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** A required layer is unreachable. Never a fallback to defaults. */
final class DriverUnavailable extends SettingsException
{
    public const int STATUS = 503;

    public const string TYPE = 'unavailable';
}
