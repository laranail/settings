<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A strict store was asked for a key that has no definition.
 */
final class UnknownSetting extends SettingsException {}
