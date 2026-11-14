<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** No change request with this id exists in the store. */
final class UnknownChangeRequest extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-change-request';
}
