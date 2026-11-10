<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** How a values row is identified besides scope, key and locale (`identity.mode`). */
enum IdentityMode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Slug and uid')]
    #[Description('A slug shared by every row of a key (derived from the key) and a ULID per row. The default.')]
    case SlugUid = 'slug_uid';

    #[Label('Slug per row')]
    #[Description('A unique slug per row: sha1 of scope, key and locale. No uid column.')]
    case SlugPerRow = 'slug_per_row';

    #[Label('Uid only')]
    #[Description('A ULID per row and no slug column.')]
    case UidOnly = 'uid_only';

    public function hasSlug(): bool
    {
        return $this !== self::UidOnly;
    }

    public function hasUid(): bool
    {
        return $this !== self::SlugPerRow;
    }
}
