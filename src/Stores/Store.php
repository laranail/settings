<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;

/**
 * A configured store: its layers in read order, the owner aliases it accepts, and its policies.
 * `locksOverridable` (`locks.overridable`) lets an actor holding `override_lock` write a locked
 * setting; it is off by default, so a lock binds everyone.
 */
final readonly class Store
{
    /**
     * @param list<Layer> $layers
     * @param list<string> $owners
     */
    public function __construct(
        public StoreName $name,
        public array $layers,
        public array $owners,
        public bool $strict,
        public bool $inherits,
        public bool $locksOverridable = false,
    ) {}

    public function accepts(string $alias): bool
    {
        return in_array($alias, $this->owners, true);
    }

    /** The first writable layer, where writes go. */
    public function writableLayer(): Layer
    {
        foreach ($this->layers as $layer) {
            if ($layer->driver->isWritable()) {
                return $layer;
            }
        }

        throw new StoreNotWritable(sprintf('Store [%s] has no writable layer.', $this->name));
    }

    /**
     * Every layer's records for these scopes, in layer order: one read per layer.
     *
     * @param list<Scope> $scopes
     *
     * @return array<string, RecordSet>
     */
    public function load(array $scopes): array
    {
        $loaded = [];

        foreach ($this->layers as $layer) {
            $loaded[$layer->name] = $layer->driver->load($this->name, $scopes);
        }

        return $loaded;
    }
}
