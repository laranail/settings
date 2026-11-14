<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** The setting is locked, or its precedence forbids store writes. */
final class LockedSetting extends SettingsException
{
    public const int STATUS = 423;

    public const string TYPE = 'locked';
}
