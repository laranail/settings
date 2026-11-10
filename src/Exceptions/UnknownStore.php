<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A store was requested that is not configured.
 */
final class UnknownStore extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-store';
}
