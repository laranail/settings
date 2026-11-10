<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The package configuration is invalid; the message names the offending config path.
 */
final class InvalidConfiguration extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'invalid-configuration';
}
