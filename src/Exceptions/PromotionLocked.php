<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * Another promotion of the same store holds the promotion lock; try again when it finishes.
 */
final class PromotionLocked extends SettingsException {}
