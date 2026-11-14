<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** Another promotion of the same store is running. */
final class PromotionLocked extends SettingsException {}
