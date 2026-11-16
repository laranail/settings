<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use RuntimeException;

/**
 * Base of every run-time failure this package throws. Messages name the store, key and scope, never
 * a value. A call the package cannot serve throws {@see SettingsMisuse} instead; catch
 * {@see SettingsError} for both.
 */
abstract class SettingsException extends RuntimeException implements SettingsError
{
    /** The HTTP status the REST API answers with. */
    public const int STATUS = 500;

    /** The RFC 9457 problem type, under Problems::TYPE_BASE. */
    public const string TYPE = 'settings-error';
}
