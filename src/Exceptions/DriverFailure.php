<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * A storage layer failed; the underlying exception is attached as the previous one.
 */
final class DriverFailure extends SettingsException {}
