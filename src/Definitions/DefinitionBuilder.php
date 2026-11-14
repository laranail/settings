<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Definitions;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\Precedence;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/** Builds one {@see Definition}; {@see register()} adds it to the registry. */
final class DefinitionBuilder
{
    private ?string $store = null;

    private ?string $type = null;

    private mixed $default = null;

    private bool $hasDefault = false;

    /** @var list<string> */
    private array $rules = [];

    private bool $encrypted = false;

    private bool $public = false;

    private bool $locked = false;

    private bool $localised = false;

    private ?bool $inherits = null;

    /** @var list<string> */
    private array $overridableBy = [];

    private Precedence $precedence = Precedence::Store;

    private ?string $label = null;

    private ?string $description = null;

    private ?string $category = null;

    private ?string $group = null;

    private bool $sensitive = false;

    private bool $requiresVerification = false;

    private bool $requiresApproval = false;

    public function __construct(
        private readonly DefinitionRegistry $registry,
        private readonly Key $key,
    ) {}

    public function inStore(string $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function inGroup(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function inCategory(string $slug): self
    {
        $this->category = $slug;

        return $this;
    }

    public function ofType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function withDefault(mixed $default): self
    {
        $this->default = $default;
        $this->hasDefault = true;

        return $this;
    }

    /** @param list<string> $rules */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function labelled(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function described(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function encrypted(): self
    {
        $this->encrypted = true;

        return $this;
    }

    public function public(): self
    {
        $this->public = true;

        return $this;
    }

    public function private(): self
    {
        $this->public = false;

        return $this;
    }

    /** Reading it needs `view_sensitive` as well as `view` (implied by {@see encrypted()}). */
    public function sensitive(): self
    {
        $this->sensitive = true;

        return $this;
    }

    /** Writing it needs a recent step-up verification of the actor. */
    public function requiresVerification(): self
    {
        $this->requiresVerification = true;

        return $this;
    }

    /** Writes become change requests; a store without an active database layer raises ApprovalUnavailable. */
    public function requiresApproval(): self
    {
        $this->requiresApproval = true;

        return $this;
    }

    public function locked(): self
    {
        $this->locked = true;

        return $this;
    }

    public function localised(): self
    {
        $this->localised = true;

        return $this;
    }

    public function inherits(): self
    {
        $this->inherits = true;

        return $this;
    }

    public function doesNotInherit(): self
    {
        $this->inherits = false;

        return $this;
    }

    public function overridableBy(string ...$aliases): self
    {
        $this->overridableBy = array_values($aliases);

        return $this;
    }

    public function precedence(Precedence $precedence): self
    {
        $this->precedence = $precedence;

        return $this;
    }

    public function build(StoreName $defaultStore): Definition
    {
        return new Definition(
            store: $this->store === null ? $defaultStore : new StoreName($this->store),
            key: $this->key,
            type: $this->type,
            default: $this->default,
            hasDefault: $this->hasDefault,
            rules: $this->rules,
            encrypted: $this->encrypted,
            public: $this->public,
            locked: $this->locked,
            localised: $this->localised,
            inherits: $this->inherits,
            overridableBy: $this->overridableBy,
            precedence: $this->precedence,
            label: $this->label,
            description: $this->description,
            category: $this->category,
            group: $this->group,
            sensitive: $this->sensitive,
            requiresVerification: $this->requiresVerification,
            requiresApproval: $this->requiresApproval,
        );
    }

    public function register(): Definition
    {
        return $this->registry->add($this);
    }
}
