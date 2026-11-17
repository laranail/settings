<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `seed.*`, inside `SettingsSetup::seed()`. */
final readonly class SeedSetup
{
    public function __construct(private SetupValues $values) {}

    /** `seed.enabled`: seed the default categories with `db:seed`. */
    public function enabled(bool $enabled = true): self
    {
        $this->values->set('seed.enabled', $enabled);

        return $this;
    }

    /** `seed.stores`: the stores categories are seeded into (none: the default store). */
    public function stores(string ...$stores): self
    {
        foreach ($stores as $store) {
            SetupValues::name('seed()->stores()', 'seed.stores', $store);
        }

        $this->values->set('seed.stores', array_values($stores));

        return $this;
    }
}
