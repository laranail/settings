<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** Moving a category under itself or a descendant would create a cycle. */
final class CategoryCycle extends SettingsException
{
    public const int STATUS = 422;

    public const string TYPE = 'category-cycle';
}
