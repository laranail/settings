<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use LogicException;

/**
 * The package was called in a way it cannot serve: reading a lookup that found nothing or is
 * redacted, `flush()` with no owner, `Settings::fake()` with no application, or a settings
 * migration before the provider is registered. Fix the calling code; retrying will not help.
 *
 * A `LogicException`, so existing catch blocks keep working, and a {@see SettingsError}.
 */
final class SettingsMisuse extends LogicException implements SettingsError {}
