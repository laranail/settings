<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A write was refused because the setting is locked or its precedence forbids store writes.
 */
final class LockedSetting extends SettingsException {}
