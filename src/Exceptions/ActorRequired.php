<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The call has no actor while `authorization.require_actor` is on, or no actor could be resolved
 * from the current request. Pass one with `as()`, or mark trusted code with `asSystem()`.
 */
final class ActorRequired extends SettingsException {}
