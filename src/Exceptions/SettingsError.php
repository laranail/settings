<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use Throwable;

/**
 * Implemented by everything this package throws, so one `catch (SettingsError $e)` takes every
 * settings failure: a {@see SettingsException} at run time, or a {@see SettingsMisuse} for a call
 * the package cannot serve.
 */
interface SettingsError extends Throwable {}
