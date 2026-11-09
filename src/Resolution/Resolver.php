<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Resolution;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\Precedence;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\ValuePipeline;

/**
 * Resolves a read through the cascade:
 *
 *   owner → (if inheritance is allowed) each parent, in its own store → global → definition default
 *
 * At each step the store's layers are tried in order and the requested locale falls back to the
 * application's fallback locale and then to the unlocalised value. Absence moves on; a stored
 * null is an answer. Each store is read once per resolution, whatever the number of steps.
 */
final readonly class Resolver
{
    public function __construct(
        private StoreRegistry $stores,
        private ValuePipeline $pipeline,
        private string $fallbackLocale,
        private PreloadedRecords $preloaded = new PreloadedRecords,
    ) {}

    /**
     * Load these scopes now, one read per store and layer, and answer later reads of them from
     * memory for the rest of the request.
     *
     * @param list<array{Store, list<Scope>}> $groups
     */
    public function preload(array $groups): void
    {
        foreach ($groups as [$store, $scopes]) {
            $this->preloaded->remember($store->name, $scopes, $store->load($scopes));
        }
    }

    /** Drop what was preloaded for one scope (it was just written). */
    public function forgetPreloaded(Store $store, Scope $scope): void
    {
        $this->preloaded->forget($store->name, $scope);
    }

    /** @param list<OwnerRef> $parents */
    public function resolve(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, array $parents, ?Definition $definition): Explanation
    {
        $plan = $this->plan($store, $owner, $parents, $definition);
        $loaded = $this->loadAll($plan);
        $precedence = $definition->precedence ?? Precedence::Store;
        $steps = [];

        foreach ($plan as [$stepStore, $scope]) {
            foreach ($this->locales($locale, $definition) as $candidate) {
                foreach ($stepStore->layers as $layer) {
                    if (! $layer->answersFor($precedence)) {
                        continue;
                    }

                    $steps[] = sprintf('%s / %s / %s / %s', $stepStore->name, $scope, $candidate->value === '' ? '(none)' : $candidate, $layer->name);
                    $record = $loaded[$stepStore->name->value][$layer->name]->find($scope, $key, $candidate);

                    if ($record !== null && $record->hasValue()) {
                        return new Explanation(Lookup::found($this->pipeline->fromRecord($record, $definition), $layer->name, $scope), $steps);
                    }
                }
            }
        }

        $steps[] = 'definition default';

        return new Explanation(
            $definition instanceof Definition && $definition->hasDefault ? Lookup::fromDefault($definition->default) : Lookup::missing(),
            $steps,
        );
    }

    /**
     * Ordered (store, scope) steps to try.
     *
     * @param list<OwnerRef> $parents
     *
     * @return list<array{Store, Scope}>
     */
    private function plan(Store $store, ?OwnerRef $owner, array $parents, ?Definition $definition): array
    {
        if (! $owner instanceof OwnerRef) {
            return [[$store, Scope::global()]];
        }

        $plan = [[$store, $owner->scope()]];

        if (($definition->inherits ?? $store->inherits) === false) {
            return $plan;
        }

        foreach ($parents as $parent) {
            $plan[] = [$this->stores->forOwner($parent->alias), $parent->scope()];
        }

        $plan[] = [$store, Scope::global()];

        return $plan;
    }

    /**
     * One load per distinct store, covering every scope the plan needs from it.
     *
     * @param list<array{Store, Scope}> $plan
     *
     * @return array<string, array<string, RecordSet>>
     */
    private function loadAll(array $plan): array
    {
        $scopes = [];
        $stores = [];

        foreach ($plan as [$store, $scope]) {
            $stores[$store->name->value] = $store;
            $scopes[$store->name->value][$scope->value] = $scope;
        }

        $loaded = [];

        foreach ($stores as $name => $store) {
            $loaded[$name] = $this->load($store, array_values($scopes[$name]));
        }

        return $loaded;
    }

    /**
     * One store's records for these scopes: preloaded scopes from memory, the rest in one read.
     *
     * @param list<Scope> $scopes
     *
     * @return array<string, RecordSet>
     */
    private function load(Store $store, array $scopes): array
    {
        $missing = array_values(array_filter($scopes, fn (Scope $scope): bool => ! $this->preloaded->has($store->name, $scope)));

        if ($missing === $scopes) {
            return $store->load($scopes);
        }

        $fresh = $missing === [] ? [] : $store->load($missing);
        $merged = [];

        foreach ($store->layers as $layer) {
            $records = isset($fresh[$layer->name]) ? $fresh[$layer->name]->all() : [];

            foreach ($scopes as $scope) {
                $records = [...$records, ...($this->preloaded->get($store->name, $scope)[$layer->name] ?? [])];
            }

            $merged[$layer->name] = new RecordSet($records);
        }

        return $merged;
    }

    /** @return list<Locale> requested → fallback → none, without duplicates */
    private function locales(Locale $requested, ?Definition $definition): array
    {
        if ($definition instanceof Definition && ! $definition->localised) {
            return [Locale::none()];
        }

        $candidates = [];

        foreach ([$requested, new Locale($this->fallbackLocale), Locale::none()] as $locale) {
            $candidates[$locale->value] = $locale;
        }

        return array_values($candidates);
    }
}
