<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * The rules from the design (§7.10) that a reviewer would otherwise have to remember.
 */
arch('every source file declares strict types')
    ->expect('Simtabi\Laranail\Settings')
    ->toUseStrictTypes();

arch('classes are final unless they are an exception base')
    ->expect('Simtabi\Laranail\Settings')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        // Bases callers catch; their subtypes are final.
        SettingsException::class,
        InvalidIdentifier::class,
    ]);

arch('nothing outside Providers and Facades reaches into the container')
    ->expect('Simtabi\Laranail\Settings')
    ->not->toUse(['app', 'resolve', 'config', 'env', 'request', 'cache', 'logger'])
    ->ignoring([
        'Simtabi\Laranail\Settings\Providers',
        'Simtabi\Laranail\Settings\Facades',
    ]);

arch('no debugging calls ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
