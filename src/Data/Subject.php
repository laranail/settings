<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * What an ability is checked against: the store, key, owner and locale, plus the flags of the
 * key's definition. Never carries a value. Gates receive it as their argument.
 */
final readonly class Subject
{
    public function __construct(
        public StoreName $store,
        public ?Key $key = null,
        public ?OwnerRef $owner = null,
        public Locale $locale = new Locale(''),
        public ?string $category = null,
        public bool $sensitive = false,
        public bool $requiresVerification = false,
        public bool $requiresApproval = false,
    ) {}

    /** The subject for one key, taking its flags from the definition when there is one. */
    public static function of(StoreName $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition): self
    {
        return new self(
            store: $store,
            key: $key,
            owner: $owner,
            locale: $locale,
            category: $definition?->category,
            sensitive: $definition?->isSensitive() ?? false,
            requiresVerification: $definition->requiresVerification ?? false,
            requiresApproval: $definition->requiresApproval ?? false,
        );
    }

    /** The scope the subject addresses: the owner's, or `global`. */
    public function scope(): Scope
    {
        return $this->owner?->scope() ?? Scope::global();
    }
}
