<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Services\Definitions\DatabaseDefinitions;

/** List a store's definitions in key order; an encrypted definition's default is left out. */
final readonly class ListDefinitions
{
    public function __construct(
        private DefinitionRegistry $registry,
        private DatabaseDefinitions $database,
        private AccessGuard $access,
    ) {}

    /** @return list<Definition> */
    public function handle(Store $store, ?Actor $actor = null): array
    {
        $this->access->authorize($actor, Ability::View, new Subject($store->name));

        $definitions = $this->database->all($store);

        foreach ($this->registry->all() as $definition) {
            if ($definition->store->value === $store->name->value) {
                $definitions[$definition->key->value] = $definition;
            }
        }

        ksort($definitions);

        return array_values($definitions);
    }
}
