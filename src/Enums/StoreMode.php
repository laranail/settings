<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** How a store's values table records owners (`stores.<name>.mode`). */
enum StoreMode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Polymorphic')]
    #[Description('settable_type and settable_id: any listed owner alias, plus global rows. The default.')]
    case Polymorphic = 'polymorphic';

    #[Label('Dedicated')]
    #[Description('A non-null owner_id foreign key to one owner table; owner rows only, the global value lives in the fallback store.')]
    case Dedicated = 'dedicated';
}
