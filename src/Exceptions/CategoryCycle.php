<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * Moving a category under itself or one of its descendants would create a cycle.
 */
final class CategoryCycle extends SettingsException {}
