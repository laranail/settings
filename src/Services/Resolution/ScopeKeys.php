<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Resolution;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/**
 * The keys one scope lists: every stored key and every defined key of the store, in key order.
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
