<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * How an export (G-10) writes encrypted values: left out (`redacted`, the default), as the
 * ciphertext stored (`ciphertext`, importable under the same `APP_KEY` or one in
 * `APP_PREVIOUS_KEYS`), or decrypted (`plaintext`, which needs `view_sensitive` and, on the
 * console, typing the store name).
 */
enum ExportMode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('Redact encrypted values')]
    case Redacted = 'redacted';

    #[Label('Export ciphertext')]
    case Ciphertext = 'ciphertext';

    #[Label('Export decrypted values')]
    case Plaintext = 'plaintext';
}
