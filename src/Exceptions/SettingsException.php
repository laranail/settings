<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use RuntimeException;

/**
 * Base of every exception this package throws, so a caller can catch anything from it in one
 * place or catch one precise subtype. Messages name the store, key and scope involved and never
 * carry a setting's value. Each subtype the REST API answers names its HTTP status and problem
 * type ({@see STATUS}, {@see TYPE}); the rest are a 500 `settings-error`.
 */
abstract class SettingsException extends RuntimeException
{
    /** The HTTP status the REST API answers this exception with; a subtype names its own. */
    public const int STATUS = 500;

    /** The problem type (RFC 9457) the REST API names, under Problems::TYPE_BASE. */
    public const string TYPE = 'settings-error';
}
