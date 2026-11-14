<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** A storage layer failed; the cause is attached as the previous exception. */
final class DriverFailure extends SettingsException {}
