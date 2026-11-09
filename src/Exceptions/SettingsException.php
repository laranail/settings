<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use RuntimeException;

/**
 * Base of every exception this package throws, so a caller can catch anything from it in one
 * place or catch one precise subtype. Messages name the store, key and scope involved and never
 * carry a setting's value.
 */
abstract class SettingsException extends RuntimeException {}
