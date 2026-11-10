<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The change request can no longer be decided: it was applied, rejected, cancelled, went stale or
 * expired (or another approver decided it first).
 */
final class ChangeRequestClosed extends SettingsException
{
    public const int STATUS = 409;

    public const string TYPE = 'change-request-closed';
}
