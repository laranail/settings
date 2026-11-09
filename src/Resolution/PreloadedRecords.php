<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Resolution;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;

/**
 * Records loaded ahead of time for a list of owners (`preload()`, `withSettings()`), so reading a
 * page of owners costs one query per store instead of one per owner (G-9). Held for the request
 * only (the resolver that owns it is scoped) and dropped for a scope when that scope is written.
 */
final class PreloadedRecords
{
    /** @var array<string, array<string, array<string, list<Record>>>> store => scope => layer => records */
    private array $records = [];

    /**
     * @param list<Scope> $scopes
     * @param array<string, RecordSet> $loaded layer => records, as the store loaded them for these scopes
     */
    public function remember(StoreName $store, array $scopes, array $loaded): void
    {
        foreach ($scopes as $scope) {
            foreach ($loaded as $layer => $records) {
                $this->records[$store->value][$scope->value][$layer] = array_values(array_filter(
                    $records->all(),
                    static fn (Record $record): bool => $record->scope->equals($scope),
                ));
            }
        }
    }

    public function has(StoreName $store, Scope $scope): bool
    {
        return isset($this->records[$store->value][$scope->value]);
    }

    /** @return array<string, list<Record>> layer => records */
    public function get(StoreName $store, Scope $scope): array
    {
        return $this->records[$store->value][$scope->value] ?? [];
    }

    public function forget(StoreName $store, Scope $scope): void
    {
        unset($this->records[$store->value][$scope->value]);
    }
}
