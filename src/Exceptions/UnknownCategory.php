<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * No category with this slug exists in the store.
 */
final class UnknownCategory extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-category';
}
