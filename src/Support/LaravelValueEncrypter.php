<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Closure;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Simtabi\Laranail\Settings\Contracts\ValueEncrypter;
use Simtabi\Laranail\Settings\Exceptions\UndecryptableValue;
use Simtabi\Laranail\Settings\Exceptions\EncryptionUnavailable;

/**
 * Laravel's encrypter, resolved only when a value is encrypted or decrypted, so an application
 * without an APP_KEY yet (before install) still boots and reads its unencrypted settings.
 * Laravel's encrypter already tries APP_PREVIOUS_KEYS when decrypting.
 */
final class LaravelValueEncrypter implements ValueEncrypter
{
    private ?StringEncrypter $encrypter = null;

    /** @param Closure(): StringEncrypter $resolver */
    public function __construct(private readonly Closure $resolver) {}

    public function encrypt(string $plaintext): string
    {
        return $this->encrypter()->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        try {
            return $this->encrypter()->decryptString($ciphertext);
        } catch (DecryptException $exception) {
            throw new UndecryptableValue('A stored value could not be decrypted with the current or previous keys; run reencrypt after a key change.', $exception->getCode(), previous: $exception);
        }
    }

    private function encrypter(): StringEncrypter
    {
        try {
            return $this->encrypter ??= ($this->resolver)();
        } catch (MissingAppKeyException $exception) {
            throw new EncryptionUnavailable('Encrypted settings need an application key; generate one before writing secrets.', $exception->getCode(), previous: $exception);
        }
    }
}
