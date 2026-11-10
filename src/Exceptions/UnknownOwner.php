<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** No owner exists for this alias and id. */
final class UnknownOwner extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-owner';
}
