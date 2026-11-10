<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Simtabi\Laranail\Settings\Data\Key;
use Illuminate\Contracts\Validation\Factory;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Validates a raw value against a definition's rules with Laravel's validator, before casting,
 * so rules such as `email` and `in:` see what the caller passed.
 */
final readonly class LaravelValueValidator
{
    public function __construct(private Factory $validator) {}

    /**
     * @param list<string> $rules
     *
     * @throws InvalidSettingValue naming the key and the failed rule, never the value
     */
    public function validate(Key $key, mixed $value, array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $validator = $this->validator->make(['value' => $value], ['value' => $rules]);

        if ($validator->fails()) {
            $failures = $validator->failed()['value'] ?? [];
            $failed = is_array($failures) ? array_keys($failures) : [];

            throw new InvalidSettingValue(sprintf(
                'Setting [%s] failed validation: %s.',
                $key,
                $failed === [] ? 'invalid' : strtolower(implode(', ', $failed)),
            ));
        }
    }
}
