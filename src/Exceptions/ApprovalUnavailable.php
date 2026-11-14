<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The write needs approval, but the store cannot hold change requests: it has no active database
 * layer or no change requests table.
 */
final class ApprovalUnavailable extends SettingsException
{
    public const int STATUS = 409;

    public const string TYPE = 'approval-unavailable';
}
