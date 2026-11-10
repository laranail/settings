<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A category belongs to another store than the setting or category referring to it.
 */
final class CategoryStoreMismatch extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'category-store-mismatch';
}
