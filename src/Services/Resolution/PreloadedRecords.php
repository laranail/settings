<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Resolution;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;

/**
 * Records preloaded for a list of owners, kept per tenant for the current request.
 */
final class PreloadedRecords
{
    /** @var array<string, array<string, array<string, list<Record>>>> tenant|store => scope => layer => records */
    private array $records = [];

    /**
     * @param list<Scope> $scopes
     * @param array<string, RecordSet> $loaded layer => records
     */
    public function remember(StoreName $store, array $scopes, array $loaded, string $tenant = ''): void
    {
        foreach ($scopes as $scope) {
            foreach ($loaded as $layer => $records) {
                $this->records[$this->slot($store, $tenant)][$scope->value][$layer] = array_values(array_filter(
                    $records->all(),
                    static fn (Record $record): bool => $record->scope->equals($scope),
                ));
            }
        }
    }

    public function has(StoreName $store, Scope $scope, string $tenant = ''): bool
    {
        return isset($this->records[$this->slot($store, $tenant)][$scope->value]);
    }

    /** @return array<string, list<Record>> layer => records */
    public function get(StoreName $store, Scope $scope, string $tenant = ''): array
    {
        return $this->records[$this->slot($store, $tenant)][$scope->value] ?? [];
    }

    public function forget(StoreName $store, Scope $scope, string $tenant = ''): void
    {
        unset($this->records[$this->slot($store, $tenant)][$scope->value]);
    }

    /** One slot per tenant and store; `|` cannot occur in a store name. */
    private function slot(StoreName $store, string $tenant): string
    {
        return $tenant . '|' . $store->value;
    }
}
