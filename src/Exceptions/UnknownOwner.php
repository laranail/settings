<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** No owner of that alias and id: the alias is not in the morph map, or no such record exists. */
final class UnknownOwner extends SettingsException
{
    public const int STATUS = 404;

    public const string TYPE = 'unknown-owner';
}
