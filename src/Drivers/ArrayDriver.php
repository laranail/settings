<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Driver;

/**
 * Holds records in memory for the lifetime of the instance.
 */
final class ArrayDriver implements Driver
{
    /** @var array<string, array<string, Record>> */
    private array $records = [];

    public function __construct(private readonly bool $writable = true) {}

    public function load(StoreName $store, array $scopes): RecordSet
    {
        $wanted = array_map(static fn (Scope $scope): string => $scope->value, $scopes);

        return new RecordSet(array_filter(
            $this->records[$store->value] ?? [],
            static fn (Record $record): bool => in_array($record->scope->value, $wanted, true),
        ));
    }

    public function put(StoreName $store, Record $record): void
    {
        $this->records[$store->value][$record->identity()] = $record;
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        unset($this->records[$store->value][Record::identityOf($scope, $key, $locale)]);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }
}
