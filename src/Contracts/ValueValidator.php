<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

interface ValueValidator
{
    /**
     * @param list<string> $rules
     *
     * @throws InvalidSettingValue naming the key and the failed rule, never the value
     */
    public function validate(Key $key, mixed $value, array $rules): void;
}
