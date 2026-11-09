<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Enums\Precedence;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;

/**
 * What a key is: its type, default, rules, flags and who may override it. Immutable; build one
 * with {@see \Simtabi\Laranail\Settings\Definitions\DefinitionBuilder}.
 */
final readonly class Definition
{
    /**
     * @param list<string> $rules validation rules applied to the raw input
     * @param list<string> $overridableBy owner aliases that may hold their own value
     */
    public function __construct(
        public StoreName $store,
        public Key $key,
        public ?string $type = null,
        public mixed $default = null,
        public bool $hasDefault = false,
        public array $rules = [],
        public bool $encrypted = false,
        public bool $public = false,
        public bool $locked = false,
        public bool $localised = false,
        public ?bool $inherits = null,
        public array $overridableBy = [],
        public Precedence $precedence = Precedence::Store,
        public ?string $label = null,
        public ?string $description = null,
        public ?string $category = null,
        public ?string $group = null,
    ) {
        if ($encrypted && $public) {
            throw new InvalidDefinition(sprintf('Setting [%s] in store [%s] cannot be both encrypted and public.', $key, $store));
        }
    }

    public function group(): string
    {
        return $this->group ?? $this->key->group();
    }

    public function allowsOwner(string $alias): bool
    {
        return $this->overridableBy === [] || in_array($alias, $this->overridableBy, true);
    }
}
