<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Resolution;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/**
 * The keys a listing of one scope walks: every key the scope holds in any layer of the store, and
 * every key a code or config definition of the store names, in key order. Shared by the PHP
 * handle's `all()` and the REST API's `GET …/settings`, so the two list the same keys.
 */
final readonly class ScopeKeys
{
    public function __construct(private DefinitionRegistry $definitions) {}

    /** @return list<string> */
    public function keys(Store $store, Scope $scope): array
    {
        $keys = [];

        foreach ($store->load([$scope]) as $records) {
            foreach ($records->all() as $record) {
                $keys[$record->key->value] = true;
            }
        }

        foreach ($this->definitions->all() as $definition) {
            if ($definition->store->value === $store->name->value) {
                $keys[$definition->key->value] = true;
            }
        }

        $keys = array_map(strval(...), array_keys($keys));
        sort($keys);

        return $keys;
    }
}
