<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings;

use LogicException;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Data\SettingWrite;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Actions\SettingActions;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;

/**
 * The {@see Settings} implementation: an immutable handle over the store, owner, locale and
 * actor it targets. Reads go through the {@see Resolver} after the {@see AccessGuard} has
 * checked them; writes go through the {@see SettingActions}, which authorize and audit.
 */
final readonly class SettingsManager implements Settings
{
    private SettingActions $actions;

    private AccessGuard $access;

    /**
     * The trailing collaborators are optional so a hand-built manager keeps working: without an
     * access guard every actor is denied (fail closed) while trusted calls behave as before.
     *
     * @param list<OwnerRef> $parents
     */
    public function __construct(
        private StoreRegistry $stores,
        private DefinitionRegistry $definitions,
        private Resolver $resolver,
        private SettingWriter $writer,
        private ?StoreName $storeName = null,
        private ?OwnerRef $owner = null,
        private array $parents = [],
        private ?Locale $selectedLocale = null,
        ?SettingActions $actions = null,
        ?AccessGuard $access = null,
        private ?RuntimeConfiguration $runtime = null,
        private ?Actor $actingAs = null,
        private ?string $reason = null,
    ) {
        $this->access = $access ?? AccessGuard::closed();
        $this->actions = $actions ?? SettingActions::using($writer, $this->access);
    }

    public function store(string $name): static
    {
        return $this->with(storeName: new StoreName($name));
    }

    public function for(Settable|OwnerRef $owner): static
    {
        [$ref, $parents] = $owner instanceof Settable ? [$owner->settingsOwner(), $owner->settingsParents()] : [$owner, []];

        return $this->with(owner: $ref, parents: $parents);
    }

    public function locale(string $locale): static
    {
        return $this->with(locale: new Locale($locale));
    }

    public function as(Authenticatable|Actor $actor): static
    {
        $actor = $actor instanceof Actor ? $actor : Actor::user($actor);

        return $this->with(actor: $this->reason === null ? $actor : $actor->withReason($this->reason));
    }

    public function asSystem(?string $reason = null): static
    {
        return $this->with(actor: Actor::system($reason ?? $this->reason));
    }

    public function asCurrentActor(?Request $request = null): static
    {
        $actor = $this->access->resolveActor($request);

        return $actor instanceof Actor
            ? $this->as($actor)
            : throw new ActorRequired('No authenticated actor could be resolved for this request.');
    }

    public function because(string $reason): static
    {
        return $this->with(actor: $this->actingAs?->withReason($reason), reason: $reason);
    }

    public function actor(): ?Actor
    {
        return $this->actingAs;
    }

    public function configuration(): RuntimeConfiguration
    {
        return $this->runtime ?? throw new LogicException('This settings handle was built without runtime configuration; resolve Settings from the container.');
    }

    public function withConfiguration(callable $change, callable $run): mixed
    {
        return $this->configuration()->temporarily($change, $run);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->lookup($key)->valueOr($default);
    }

    public function lookup(string $key): Lookup
    {
        [$store, $settingKey, $definition, $subject] = $this->readTarget($key);

        if ($subject->sensitive) {
            $this->access->authorize($this->actingAs, Ability::ViewSensitive, $subject);
        }

        return $this->resolve($store, $settingKey, $definition)->lookup;
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
        [$store, $settingKey, $definition, $subject] = $this->readTarget($key);
        $explanation = $this->resolve($store, $settingKey, $definition);

        if ($subject->sensitive && $this->actingAs instanceof Actor && ! $this->access->allows($this->actingAs, Ability::ViewSensitive, $subject)) {
            return $explanation->redacted();
        }

        return $explanation;
    }

    public function set(string $key, mixed $value): void
    {
        $this->actions->set->handle(new SettingWrite($this->writeTarget($key), $value), $this->actingAs);
    }

    public function forget(string $key): void
    {
        $this->actions->forget->handle($this->writeTarget($key), $this->actingAs);
    }

    public function lock(string $key): void
    {
        $this->actions->lock->handle($this->writeTarget($key), $this->actingAs);
    }

    public function unlock(string $key): void
    {
        $this->actions->unlock->handle($this->writeTarget($key), $this->actingAs);
    }

    /**
     * The store, key, definition and subject of a read, after checking `view` (or that an actor
     * is not required) and the strict-store rule.
     *
     * @return array{Store, Key, ?Definition, Subject}
     */
    private function readTarget(string $key): array
    {
        $settingKey = new Key($key);
        $store = $this->targetStore();
        $definition = $this->definition($store, $settingKey);
        $subject = Subject::of($store->name, $settingKey, $this->activeLocale(), $this->owner, $definition);

        $this->access->authorize($this->actingAs, Ability::View, $subject);

        if (! $definition instanceof Definition && $store->strict) {
            throw new UnknownSetting(sprintf('Store [%s] is strict and has no definition for [%s].', $store->name, $settingKey));
        }

        return [$store, $settingKey, $definition, $subject];
    }

    private function resolve(Store $store, Key $key, ?Definition $definition): Explanation
    {
        return $this->resolver->resolve($store, $key, $this->activeLocale(), $this->owner, $this->parents, $definition);
    }

    private function writeTarget(string $key): SettingTarget
    {
        $settingKey = new Key($key);
        $store = $this->targetStore();

        return new SettingTarget(
            $store,
            $settingKey,
            $this->activeLocale(),
            $this->owner,
            $this->definition($store, $settingKey),
            $this->reason ?? $this->actingAs?->context->reason,
        );
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

    /** A definition registered at runtime for this request wins over the shared registry. */
    private function definition(Store $store, Key $key): ?Definition
    {
        return $this->runtime?->findDefinition($store->name, $key) ?? $this->definitions->find($store->name, $key);
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

    /** @param list<OwnerRef>|null $parents */
    private function with(
        ?StoreName $storeName = null,
        ?OwnerRef $owner = null,
        ?array $parents = null,
        ?Locale $locale = null,
        ?Actor $actor = null,
        ?string $reason = null,
    ): self {
        return new self(
            $this->stores,
            $this->definitions,
            $this->resolver,
            $this->writer,
            $storeName ?? $this->storeName,
            $owner ?? $this->owner,
            $parents ?? $this->parents,
            $locale ?? $this->selectedLocale,
            $this->actions,
            $this->access,
            $this->runtime,
            $actor ?? $this->actingAs,
            $reason ?? $this->reason,
        );
    }
}
