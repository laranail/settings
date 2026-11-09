<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * The closed set of built-in layer drivers.
 *
 * Driver names come from configuration an operator edits, so they resolve through this enum
 * and an exhaustive match, never by interpolating the name into a method or class name.
 * Custom drivers are registered with closures on the DriverFactory.
 */
enum DriverType: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Database')]
    #[Description('A table on a database connection, through the query builder; version-checked writes.')]
    case Database = 'database';

    #[Label('In memory')]
    #[Description('Holds values for the current process only; used by tests and fakes.')]
    case Array = 'array';

    #[Label('JSON file')]
    #[Description('A JSON file on a filesystem disk; writable unless configured otherwise.')]
    case Json = 'json';

    #[Label('PHP file')]
    #[Description('A read-only PHP file returning key => value; suited to shipped defaults.')]
    case Php = 'php';

    #[Label('Config')]
    #[Description('A read-only view over a Laravel config prefix.')]
    case Config = 'config';
}
