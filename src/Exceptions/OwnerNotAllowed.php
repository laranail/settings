<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The owner's morph alias is not allowed in the store, or no store accepts it.
 */
final class OwnerNotAllowed extends SettingsException {}
