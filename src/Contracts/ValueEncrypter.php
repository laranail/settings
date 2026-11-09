<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Exceptions\UndecryptableValue;
use Simtabi\Laranail\Settings\Exceptions\EncryptionUnavailable;

interface ValueEncrypter
{
    /** @throws EncryptionUnavailable */
    public function encrypt(string $plaintext): string;

    /**
     * @throws EncryptionUnavailable
     * @throws UndecryptableValue
     */
    public function decrypt(string $ciphertext): string;
}
