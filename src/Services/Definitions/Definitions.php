<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Definitions;

use Simtabi\Laranail\Settings\Data\Key;
use Illuminate\Container\Attributes\Scoped;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * Which definition applies to a key (D-6): one registered at runtime for this request, then code
 * and config, then the definitions table. Scoped, like the runtime configuration it reads.
 */
#[Scoped]
final readonly class Definitions
{
    public function __construct(
        private RuntimeConfiguration $runtime,
        private DefinitionRegistry $registry,
        private DatabaseDefinitions $database,
    ) {}

    public function find(Store $store, Key $key): ?Definition
    {
        return $this->findInCode($store->name, $key) ?? $this->database->find($store, $key);
    }

    /**
     * Runtime, then code and config, without the definitions table: what an alert listener reads,
     * so delivering an alert never queries the database.
     */
    public function findInCode(StoreName $store, Key $key): ?Definition
    {
        return $this->runtime->findDefinition($store, $key) ?? $this->registry->find($store, $key);
    }
}
