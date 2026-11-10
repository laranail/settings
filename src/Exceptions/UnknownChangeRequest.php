<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** No change request with this id exists in the store (for the current tenant). */
final class UnknownChangeRequest extends SettingsException {}
