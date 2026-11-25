<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** The key type of a dedicated store's owner table (`stores.<name>.owner.type`); unset, it follows `laranail.db-tools.id_type`. */
enum IdType: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Big integer')]
    #[Description('An unsigned big integer. The default.')]
    case Bigint = 'bigint';

    #[Label('UUID')]
    #[Description('A UUID (36 characters).')]
    case Uuid = 'uuid';

    #[Label('ULID')]
    #[Description('A ULID (26 characters).')]
    case Ulid = 'ulid';

    /** Read a configured value, accepting db-tools' upper-case names too. */
    public static function parse(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
