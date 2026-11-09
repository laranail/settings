<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;

/**
 * The {@see Settings} implementation: an immutable handle over the store, owner and locale it
 * targets. Reads go through the {@see Resolver}, writes through the {@see SettingWriter}.
 */
final readonly class SettingsManager implements Settings
{
    /** @param list<OwnerRef> $parents */
    public function __construct(
        private StoreRegistry $stores,
        private DefinitionRegistry $definitions,
        private Resolver $resolver,
        private SettingWriter $writer,
        private ?StoreName $storeName = null,
        private ?OwnerRef $owner = null,
        private array $parents = [],
        private ?Locale $selectedLocale = null,
    ) {}

    public function store(string $name): static
    {
        return new self($this->stores, $this->definitions, $this->resolver, $this->writer, new StoreName($name), $this->owner, $this->parents, $this->selectedLocale);
    }

    public function for(Settable|OwnerRef $owner): static
    {
        [$ref, $parents] = $owner instanceof Settable ? [$owner->settingsOwner(), $owner->settingsParents()] : [$owner, []];

        return new self($this->stores, $this->definitions, $this->resolver, $this->writer, $this->storeName, $ref, $parents, $this->selectedLocale);
    }

    public function locale(string $locale): static
    {
        return new self($this->stores, $this->definitions, $this->resolver, $this->writer, $this->storeName, $this->owner, $this->parents, new Locale($locale));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->lookup($key)->valueOr($default);
    }

    public function lookup(string $key): Lookup
    {
        return $this->explain($key)->lookup;
    }

    public function has(string $key): bool
    {
        return $this->lookup($key)->isFound();
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        return is_string($value) ? $value : throw $this->mismatch($key, 'a string');
    }

    public function integer(string $key): int
    {
        $value = $this->required($key);

        return is_int($value) ? $value : throw $this->mismatch($key, 'an integer');
    }

    public function float(string $key): float
    {
        $value = $this->required($key);

        return is_float($value) || is_int($value) ? (float) $value : throw $this->mismatch($key, 'a float');
    }

    public function boolean(string $key): bool
    {
        $value = $this->required($key);

        return is_bool($value) ? $value : throw $this->mismatch($key, 'a boolean');
    }

    public function array(string $key): array
    {
        $value = $this->required($key);

        return is_array($value) ? $value : throw $this->mismatch($key, 'an array');
    }

    public function explain(string $key): Explanation
    {
        $settingKey = new Key($key);
        $store = $this->targetStore();
        $definition = $this->definition($store, $settingKey);

        if (! $definition instanceof Definition && $store->strict) {
            throw new UnknownSetting(sprintf('Store [%s] is strict and has no definition for [%s].', $store->name, $settingKey));
        }

        return $this->resolver->resolve($store, $settingKey, $this->activeLocale(), $this->owner, $this->parents, $definition);
    }

    public function set(string $key, mixed $value): void
    {
        $settingKey = new Key($key);
        $store = $this->targetStore();

        $this->writer->set($store, $settingKey, $this->activeLocale(), $this->owner, $this->definition($store, $settingKey), $value);
    }

    public function forget(string $key): void
    {
        $settingKey = new Key($key);
        $store = $this->targetStore();

        $this->writer->forget($store, $settingKey, $this->activeLocale(), $this->owner, $this->definition($store, $settingKey));
    }

    public function lock(string $key): void
    {
        $this->writer->lock($this->targetStore(), new Key($key), $this->activeLocale(), $this->owner);
    }

    public function unlock(string $key): void
    {
        $this->writer->unlock($this->targetStore(), new Key($key), $this->activeLocale(), $this->owner);
    }

    private function targetStore(): Store
    {
        if ($this->storeName instanceof StoreName) {
            $store = $this->stores->get($this->storeName);

            if ($this->owner instanceof OwnerRef && ! $store->accepts($this->owner->alias)) {
                throw new OwnerNotAllowed(sprintf('Store [%s] does not accept owner alias [%s].', $store->name, $this->owner->alias));
            }

            return $store;
        }

        return $this->owner instanceof OwnerRef ? $this->stores->forOwner($this->owner->alias) : $this->stores->default();
    }

    private function activeLocale(): Locale
    {
        return $this->selectedLocale ?? Locale::none();
    }

    private function definition(Store $store, Key $key): ?Definition
    {
        return $this->definitions->find($store->name, $key);
    }

    private function required(string $key): mixed
    {
        $lookup = $this->lookup($key);

        return $lookup->isFound() ? $lookup->value() : throw new TypeMismatch(sprintf('Setting [%s] has no value.', $key));
    }

    private function mismatch(string $key, string $expected): TypeMismatch
    {
        return new TypeMismatch(sprintf('Setting [%s] is not %s.', $key, $expected));
    }
}
