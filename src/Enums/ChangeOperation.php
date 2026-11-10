<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** The write a change request applies once it is approved. */
enum ChangeOperation: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Set')]
    case Set = 'set';

    #[Label('Forget')]
    case Forget = 'forget';

    #[Label('Lock')]
    case Lock = 'lock';

    #[Label('Unlock')]
    case Unlock = 'unlock';

    /** The ability the requester needs to propose this operation. */
    public function ability(): Ability
    {
        return match ($this) {
            self::Set    => Ability::Update,
            self::Forget => Ability::Forget,
            self::Lock   => Ability::Lock,
            self::Unlock => Ability::Unlock,
        };
    }
}
