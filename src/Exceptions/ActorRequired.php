<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The call has no actor while one is required. Pass one with `as()`, or mark trusted code with
 * `asSystem()`.
 */
final class ActorRequired extends SettingsException
{
    public const int STATUS = 401;

    public const string TYPE = 'actor-required';
}
