<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** Which source wins for a setting, declared on its definition. */
enum Precedence: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Store')]
    #[Description('Layer order decides; writes go to the first writable layer.')]
    case Store = 'store';

    #[Label('Shipped file')]
    #[Description('A read-only file layer wins; writes are refused.')]
    case File = 'file';

    #[Label('Config')]
    #[Description('A config layer wins; writes are refused.')]
    case Config = 'config';

    public function allowsWrites(): bool
    {
        return $this === self::Store;
    }
}
