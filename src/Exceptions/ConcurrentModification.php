<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** Another writer changed the row first. Re-read and retry. */
final class ConcurrentModification extends SettingsException
{
    public const int STATUS = 412;

    public const string TYPE = 'precondition-failed';
}
