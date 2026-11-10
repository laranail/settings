<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** An encrypted setting was used before an encryption key exists. */
final class EncryptionUnavailable extends SettingsException {}
