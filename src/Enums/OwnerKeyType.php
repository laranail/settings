<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** How a polymorphic store types `settable_id` (`ids.owner_key`). */
enum OwnerKeyType: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('String')]
    #[Description('settable_id as string(36): any owner key type fits. The default.')]
    case String = 'string';

    #[Label('Typed')]
    #[Description('settable_id typed like the owners\' keys (laranail.db-tools.id_type), for stores whose owners share one key type.')]
    case Typed = 'typed';
}
