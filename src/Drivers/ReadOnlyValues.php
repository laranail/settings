<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Turns a flat `key => value` map into global, unlocalised records for the read-only drivers.
 */
final readonly class ReadOnlyValues
{
    public function __construct(private ValueEncoder $encoder) {}

    /**
     * @param array<array-key, mixed> $values
     * @param list<Scope> $scopes
     */
    public function toRecordSet(array $values, array $scopes, string $source): RecordSet
    {
        $wantsGlobal = array_filter($scopes, static fn (Scope $scope): bool => $scope->isGlobal()) !== [];

        if (! $wantsGlobal) {
            return new RecordSet;
        }

        $records = [];

        foreach ($values as $name => $value) {
            try {
                $key = new Key((string) $name);
                $records[] = new Record(Scope::global(), $key, Locale::none(), $this->encoder->encode($key, $value));
            } catch (InvalidIdentifier|InvalidSettingValue $exception) {
                throw new DriverFailure(sprintf('[%s] holds an unusable entry [%s].', $source, $name), $exception->getCode(), previous: $exception);
            }
        }

        return new RecordSet($records);
    }
}
