<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A setting definition is internally inconsistent (for example encrypted and public).
 */
final class InvalidDefinition extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'invalid-definition';
}
