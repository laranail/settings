<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A value failed its definition's rules, cast or locale constraints.
 */
final class InvalidSettingValue extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'invalid-value';
}
