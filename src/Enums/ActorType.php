<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * Who an actor is: a user, trusted system code, an API token or a named service.
 */
enum ActorType: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('User')]
    case User = 'user';

    #[Label('System')]
    case System = 'system';

    #[Label('API token')]
    case Token = 'token';

    #[Label('Service')]
    case Service = 'service';
}
