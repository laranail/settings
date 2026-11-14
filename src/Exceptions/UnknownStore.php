<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** The store is not configured. */
final class UnknownStore extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-store';
}
