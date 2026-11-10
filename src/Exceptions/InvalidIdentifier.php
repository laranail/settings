<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** A store name, key, locale or owner alias is invalid. */
class InvalidIdentifier extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'invalid-identifier';
}
