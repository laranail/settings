<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A strict store was asked for a key that has no definition.
 */
final class UnknownSetting extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-setting';
}
