<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Definitions;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\Precedence;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;

/**
 * Every setting definition from code and from the `definitions` config map.
 */
final class DefinitionRegistry
{
    /**
     * Names under `laranail.settings.definitions` that are options, not setting keys. `table` is
     * no longer an option; it stays so an older config file never defines a setting named `table`.
     */
    public const array OPTIONS = ['table', 'sources', 'allowed_rules'];

    /** @var array<string, Definition> */
    private array $definitions = [];

    private bool $configLoaded = false;

    /** @param array<array-key, mixed> $configured `key => attributes` from config */
    public function __construct(
        private readonly StoreName $defaultStore,
        private readonly CasterRegistry $casters,
        private readonly array $configured = [],
    ) {}

    /** The store a definition without `inStore()` belongs to. */
    public function defaultStore(): StoreName
    {
        return $this->defaultStore;
    }

    public function define(string $key): DefinitionBuilder
    {
        return new DefinitionBuilder($this, new Key($key));
    }

    public function add(DefinitionBuilder $builder): Definition
    {
        $definition = $builder->build($this->defaultStore);

        if ($definition->type !== null && ! $this->casters->has($definition->type)) {
            throw new InvalidDefinition(sprintf('Setting [%s] uses unknown type [%s]; register a caster for it.', $definition->key, $definition->type));
        }

        return $this->definitions[$this->index($definition->store, $definition->key)] = $definition;
    }

    public function find(StoreName $store, Key $key): ?Definition
    {
        $this->loadConfigured();

        return $this->definitions[$this->index($store, $key)] ?? null;
    }

    /**
     * Every definition registered in code and config, in registration order.
     *
     * @return list<Definition>
     */
    public function all(): array
    {
        $this->loadConfigured();

        return array_values($this->definitions);
    }

    /**
     * Build a definition from config-style attributes without registering it.
     *
     * @param array<array-key, mixed> $attributes
     *
     * @throws InvalidDefinition
     */
    public function fromAttributes(string $key, array $attributes, StoreName $store): Definition
    {
        $definition = $this->fromConfig($key, ['store' => $store->value] + $attributes)->build($this->defaultStore);

        if ($definition->type !== null && ! $this->casters->has($definition->type)) {
            throw new InvalidDefinition(sprintf('Setting [%s] uses unknown type [%s]; register a caster for it.', $definition->key, $definition->type));
        }

        return $definition;
    }

    private function loadConfigured(): void
    {
        if ($this->configLoaded) {
            return;
        }

        $this->configLoaded = true;

        foreach ($this->configured as $key => $attributes) {
            if (in_array($key, self::OPTIONS, true)) {
                continue;
            }

            if (! is_array($attributes)) {
                throw new InvalidDefinition(sprintf('laranail.settings.definitions.%s: an array of attributes is required.', $key));
            }

            $this->fromConfig((string) $key, $attributes)->register();
        }
    }

    /** @param array<array-key, mixed> $attributes */
    private function fromConfig(string $key, array $attributes): DefinitionBuilder
    {
        $builder = $this->define($key);
        $string = static fn (string $name): ?string => is_string($attributes[$name] ?? null) ? $attributes[$name] : null;

        if (($store = $string('store')) !== null) {
            $builder->inStore($store);
        }

        if (($type = $string('type')) !== null) {
            $builder->ofType($type);
        }

        if (array_key_exists('default', $attributes)) {
            $builder->withDefault($attributes['default']);
        }

        if (is_array($attributes['rules'] ?? null)) {
            $builder->rules(array_values(array_filter($attributes['rules'], is_string(...))));
        }

        foreach (['encrypted' => 'encrypted', 'public' => 'public', 'locked' => 'locked', 'localised' => 'localised', 'sensitive' => 'sensitive', 'requires_verification' => 'requiresVerification', 'requires_approval' => 'requiresApproval'] as $flag => $method) {
            if (($attributes[$flag] ?? false) === true) {
                $builder->{$method}();
            }
        }

        if (array_key_exists('inherits', $attributes)) {
            $attributes['inherits'] === true ? $builder->inherits() : $builder->doesNotInherit();
        }

        if (is_array($attributes['overridable_by'] ?? null)) {
            $builder->overridableBy(...array_values(array_filter($attributes['overridable_by'], is_string(...))));
        }

        if (($precedence = $string('precedence')) !== null) {
            $builder->precedence(Precedence::tryFrom($precedence) ?? throw new InvalidDefinition(sprintf('laranail.settings.definitions.%s.precedence: unknown value [%s].', $key, $precedence)));
        }

        foreach (['label' => 'labelled', 'description' => 'described', 'category' => 'inCategory', 'group' => 'inGroup'] as $field => $method) {
            if (($value = $string($field)) !== null) {
                $builder->{$method}($value);
            }
        }

        return $builder;
    }

    private function index(StoreName $store, Key $key): string
    {
        return $store->value . '|' . $key->value;
    }
}
