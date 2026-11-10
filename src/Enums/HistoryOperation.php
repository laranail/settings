<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * What produced a history entry (§4.3). A `forget` entry is a tombstone: it records the version
 * that was removed and has no new value; the next write continues the version after it.
 */
enum HistoryOperation: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Set')]
    case Set = 'set';

    #[Label('Forgotten')]
    case Forget = 'forget';

    #[Label('Locked')]
    case Lock = 'lock';

    #[Label('Unlocked')]
    case Unlock = 'unlock';

    #[Label('Rolled back')]
    case Rollback = 'rollback';
}
