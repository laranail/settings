<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * How categories and definitions store their titles, labels and descriptions (D-8,
 * `translations.mode`).
 */
enum TranslationsMode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Lang keys or text')]
    #[Description('title, label and description are plain text (or translation keys). The default.')]
    case Lang = 'lang';

    #[Label('JSON per locale')]
    #[Description('title, label and description are JSON maps of locale to text.')]
    case Json = 'json';
}
