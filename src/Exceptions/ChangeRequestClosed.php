<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** The change request was already applied, rejected, cancelled, expired or went stale. */
final class ChangeRequestClosed extends SettingsException
{
    public const int STATUS = 409;

    public const string TYPE = 'change-request-closed';
}
