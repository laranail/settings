<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** A strict store has no definition for this key. */
final class UnknownSetting extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-setting';
}
