<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** A typed getter found a value of a different type. */
final class TypeMismatch extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'type-mismatch';
}
