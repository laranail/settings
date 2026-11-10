<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Resolution;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Lookup;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\Precedence;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;

/**
 * Resolves a read through the cascade: owner, parents, global value, then the definition default.
 *
 * Layers are tried in order at each step, and a stored null is an answer. Each store is read
 * once per resolution.
 */
#[Scoped]
final readonly class Resolver
{
    public function __construct(
        private StoreRegistry $stores,
        private ValuePipeline $pipeline,
        #[Config('app.fallback_locale', 'en')]
        private string $fallbackLocale,
        private PreloadedRecords $preloaded = new PreloadedRecords,
        private ?TenantDiscriminator $tenant = null,
    ) {}

    /**
     * Load these scopes now and answer later reads of them from memory for the rest of the request.
     *
     * @param list<array{Store, list<Scope>}> $groups
     */
    public function preload(array $groups): void
    {
        foreach ($groups as [$store, $scopes]) {
            $this->preloaded->remember($store->name, $scopes, $store->load($scopes), $this->tenant());
        }
    }

    /** Drop what was preloaded for one scope after a write. */
    public function forgetPreloaded(Store $store, Scope $scope): void
    {
        $this->preloaded->forget($store->name, $scope, $this->tenant());
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
     * The store and scope steps to try, in order.
     *
     * @param list<OwnerRef> $parents
     *
     * @return list<array{Store, Scope}>
     */
    private function plan(Store $store, ?OwnerRef $owner, array $parents, ?Definition $definition): array
    {
        // A dedicated store has no global rows: its global step reads its fallback store.
        $global = $this->stores->globalOf($store);

        if (! $owner instanceof OwnerRef) {
            return [[$global, Scope::global()]];
        }

        $plan = [[$store, $owner->scope()]];

        if (($definition->inherits ?? $store->inherits) === false) {
            return $plan;
        }

        foreach ($parents as $parent) {
            $plan[] = [$this->stores->forOwner($parent->alias), $parent->scope()];
        }

        $plan[] = [$global, Scope::global()];

        return $plan;
    }

    /**
     * Load each distinct store once for every scope the plan needs from it.
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
     * One store's records for these scopes, from memory where preloaded.
     *
     * @param list<Scope> $scopes
     *
     * @return array<string, RecordSet>
     */
    private function load(Store $store, array $scopes): array
    {
        $tenant = $this->tenant();
        $missing = array_values(array_filter($scopes, fn (Scope $scope): bool => ! $this->preloaded->has($store->name, $scope, $tenant)));

        if ($missing === $scopes) {
            return $store->load($scopes);
        }

        $fresh = $missing === [] ? [] : $store->load($missing);
        $merged = [];

        foreach ($store->layers as $layer) {
            $records = isset($fresh[$layer->name]) ? $fresh[$layer->name]->all() : [];

            foreach ($scopes as $scope) {
                $records = [...$records, ...($this->preloaded->get($store->name, $scope, $tenant)[$layer->name] ?? [])];
            }

            $merged[$layer->name] = new RecordSet($records);
        }

        return $merged;
    }

    /** @return list<Locale> the requested, fallback and unlocalised locales, without duplicates */
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

    /** The current tenant's key, which partitions the preloaded records. */
    private function tenant(): string
    {
        return $this->tenant?->current() ?? '';
    }
}
