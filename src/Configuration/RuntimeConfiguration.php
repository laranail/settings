<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configuration;

use Closure;
use Psr\Log\NullLogger;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Verification\ClosureVerifier;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Authorization\ClosureAuthorizer;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Authentication\ClosureActorResolver;

/**
 * Configuration changed in code, for the current request or job only.
 *
 * It is bound `scoped`, so Octane and queue workers flush it between requests: nothing set here
 * outlives the request that set it. Store and layer changes are validated exactly like config
 * (by building the stores) before they are accepted, so a bad change fails where it is made.
 * Store and layer entries are merged over `laranail.settings.stores` / `.layers`; later wins.
 */
final class RuntimeConfiguration
{
    /** @var array<string, array<array-key, mixed>> */
    private array $stores = [];

    /** @var array<string, array<array-key, mixed>> */
    private array $layers = [];

    /** @var array<string, Closure(LayerConfig): Driver> */
    private array $drivers = [];

    private DefinitionRegistry $definitions;

    private ?Authorizer $authorizer = null;

    private ?Verifier $verifier = null;

    private ?ActorResolver $actorResolver = null;

    /** @var list<AuditSink> */
    private array $auditSinks = [];

    private ?LoggerInterface $logger = null;

    private ?bool $requireActor = null;

    private int $generation = 0;

    /**
     * @param array<array-key, mixed> $storeConfig `laranail.settings.stores`
     * @param array<array-key, mixed> $layerConfig `laranail.settings.layers`
     * @param (Closure(string): void)|null $onChange told about each accepted change (audit and log)
     */
    public function __construct(
        private readonly DriverFactory $driverFactory,
        private readonly string $defaultStore,
        private readonly array $storeConfig,
        private readonly array $layerConfig,
        CasterRegistry $casters,
        private readonly ?Closure $onChange = null,
    ) {
        $this->definitions = new DefinitionRegistry(new StoreName($defaultStore), $casters);
    }

    /**
     * Add or replace a store for this request.
     *
     * @param array<array-key, mixed> $config the same shape as `laranail.settings.stores.<name>`
     *
     * @throws InvalidConfiguration
     */
    public function addStore(string $name, array $config): self
    {
        $stores = [...$this->stores, $name => $config];
        $this->validate($stores, $this->layers, $this->drivers);
        $this->stores = $stores;

        return $this->changed(sprintf('store [%s] configured', $name));
    }

    /**
     * Add or replace a layer for this request.
     *
     * @param array<array-key, mixed> $config the same shape as `laranail.settings.layers.<name>`
     *
     * @throws InvalidConfiguration
     */
    public function addLayer(string $name, array $config): self
    {
        $layers = [...$this->layers, $name => $config];
        $this->validate($this->stores, $layers, $this->drivers);
        $this->layers = $layers;

        return $this->changed(sprintf('layer [%s] configured', $name));
    }

    /**
     * Register a custom driver for this request.
     *
     * @param Closure(LayerConfig): Driver $factory
     *
     * @throws InvalidConfiguration when the name is a built-in driver
     */
    public function extendDriver(string $name, Closure $factory): self
    {
        $drivers = [...$this->drivers, $name => $factory];
        $this->validate($this->stores, $this->layers, $drivers);
        $this->drivers = $drivers;

        return $this->changed(sprintf('driver [%s] registered', $name));
    }

    /** Define a setting for this request; call `register()` on the builder to add it. */
    public function define(string $key): DefinitionBuilder
    {
        $this->changed(sprintf('definition [%s] defined', $key));

        return $this->definitions->define($key);
    }

    /**
     * Replace the configured authorizer for this request.
     *
     * @param Authorizer|Closure(Actor, Ability, Subject): (Decision|bool|null) $authorizer
     */
    public function authorizeUsing(Authorizer|Closure $authorizer): self
    {
        $this->authorizer = $authorizer instanceof Closure ? new ClosureAuthorizer($authorizer) : $authorizer;

        return $this->changed('authorizer replaced');
    }

    /**
     * Replace the configured verifier for this request.
     *
     * @param Verifier|Closure(Actor, Subject): bool $verifier
     */
    public function verifyUsing(Verifier|Closure $verifier): self
    {
        $this->verifier = $verifier instanceof Closure ? new ClosureVerifier($verifier) : $verifier;

        return $this->changed('verifier replaced');
    }

    /**
     * Replace the configured actor resolver for this request.
     *
     * @param ActorResolver|Closure(?Request): ?Actor $resolver
     */
    public function resolveActorsUsing(ActorResolver|Closure $resolver): self
    {
        $this->actorResolver = $resolver instanceof Closure ? new ClosureActorResolver($resolver) : $resolver;

        return $this->changed('actor resolver replaced');
    }

    /** Send audit entries to these sinks as well as the configured ones. */
    public function auditTo(AuditSink ...$sinks): self
    {
        $this->auditSinks = [...$this->auditSinks, ...array_values($sinks)];

        return $this->changed('audit sink added');
    }

    /** Send the operational log to this logger, or nowhere with `false`. */
    public function logTo(LoggerInterface|false $logger): self
    {
        $this->changed('log destination replaced');
        $this->logger = $logger === false ? new NullLogger : $logger;

        return $this;
    }

    /** Refuse calls without an actor for this request (`authorization.require_actor`). */
    public function requireActor(): self
    {
        $this->requireActor = true;

        return $this->changed('actor required');
    }

    /** Accept calls without an actor for this request, whatever the config says. */
    public function allowActorless(): self
    {
        $this->requireActor = false;

        return $this->changed('actorless calls allowed');
    }

    /**
     * Apply `$change`, run `$run`, then restore everything `$change` altered, even when `$run`
     * throws.
     *
     * @template TResult
     *
     * @param callable(self): mixed $change
     * @param callable(): TResult $run
     *
     * @return TResult
     */
    public function temporarily(callable $change, callable $run): mixed
    {
        $snapshot = [
            $this->stores, $this->layers, $this->drivers, clone $this->definitions, $this->authorizer,
            $this->verifier, $this->actorResolver, $this->auditSinks, $this->logger, $this->requireActor,
        ];

        try {
            $change($this);

            return $run();
        } finally {
            [
                $this->stores, $this->layers, $this->drivers, $this->definitions, $this->authorizer,
                $this->verifier, $this->actorResolver, $this->auditSinks, $this->logger, $this->requireActor,
            ] = $snapshot;
            $this->changed('runtime configuration restored');
        }
    }

    public function findDefinition(StoreName $store, Key $key): ?Definition
    {
        return $this->definitions->find($store, $key);
    }

    /** @return array<string, array<array-key, mixed>> */
    public function stores(): array
    {
        return $this->stores;
    }

    /** @return array<string, array<array-key, mixed>> */
    public function layers(): array
    {
        return $this->layers;
    }

    /** @return array<string, Closure(LayerConfig): Driver> */
    public function drivers(): array
    {
        return $this->drivers;
    }

    public function authorizer(): ?Authorizer
    {
        return $this->authorizer;
    }

    public function verifier(): ?Verifier
    {
        return $this->verifier;
    }

    public function actorResolver(): ?ActorResolver
    {
        return $this->actorResolver;
    }

    /** @return list<AuditSink> */
    public function auditSinks(): array
    {
        return $this->auditSinks;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /** True or false when set for this request; null defers to `authorization.require_actor`. */
    public function actorRequirement(): ?bool
    {
        return $this->requireActor;
    }

    /** Bumped on every change, so the store registry knows to rebuild. */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * @param array<array-key, mixed> $stores
     * @param array<array-key, mixed> $layers
     * @param array<string, Closure(LayerConfig): Driver> $drivers
     */
    private function validate(array $stores, array $layers, array $drivers): void
    {
        new StoreRegistry(
            $this->driverFactory->withExtensions($drivers),
            $this->defaultStore,
            array_replace($this->storeConfig, $stores),
            array_replace($this->layerConfig, $layers),
        )->validate();
    }

    private function changed(string $change): self
    {
        $this->generation++;

        if ($this->onChange instanceof Closure) {
            ($this->onChange)($change);
        }

        return $this;
    }
}
