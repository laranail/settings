<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** The store keeps no history, or the requested version is not in it. */
final class HistoryUnavailable extends SettingsException
{
    public const int STATUS = 409;

    public const string TYPE = 'history-unavailable';
}
