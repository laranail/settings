<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;

/** Where a configurator's value stage runs; the only two places one may. */
enum Stage: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Before write')]
    #[Description('The value a caller sets, before validation, casting and encryption.')]
    case BeforeWrite = 'before_write';

    #[Label('After read')]
    #[Description('The value a read returns, after decryption and casting and before redaction.')]
    case AfterRead = 'after_read';
}
