<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `cache.*`, inside `SettingsSetup::cache()`. */
final readonly class CacheSetup
{
    public function __construct(private SetupValues $values) {}

    public function enabled(bool $enabled = true): self
    {
        $this->values->set('cache.enabled', $enabled);

        return $this;
    }

    /** `cache.store`: a Laravel cache store name, or null for the default store. */
    public function store(?string $store): self
    {
        $this->values->set('cache.store', $store === null ? null : SetupValues::filled('cache()->store()', 'cache.store', $store));

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->values->set('cache.prefix', SetupValues::filled('cache()->prefix()', 'cache.prefix', $prefix));

        return $this;
    }

    /** `cache.ttl`: seconds a cached bag is fresh. */
    public function ttl(int $seconds): self
    {
        $this->values->set('cache.ttl', SetupValues::atLeast('cache()->ttl()', 'cache.ttl', $seconds, 1));

        return $this;
    }

    /** `cache.stale`: seconds a stale bag may still be served while it refreshes. */
    public function stale(int $seconds): self
    {
        $this->values->set('cache.stale', SetupValues::atLeast('cache()->stale()', 'cache.stale', $seconds, 0));

        return $this;
    }
}
