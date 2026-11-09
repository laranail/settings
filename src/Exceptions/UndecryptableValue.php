<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * An encrypted value could not be decrypted with the current or previous keys.
 */
final class UndecryptableValue extends SettingsException {}
