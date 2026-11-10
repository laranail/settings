<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** No store accepts the owner's morph alias. */
final class OwnerNotAllowed extends SettingsException
{
    public const int STATUS = 409;

    public const string TYPE = 'owner-not-allowed';
}
