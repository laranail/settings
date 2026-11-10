<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Contracts\Settable;

/**
 * Where a setting is, as a caller outside PHP names it: a store, a key, an owner (or the global
 * value) and a locale ('' for none).
 */
final readonly class SettingAddress
{
    public function __construct(
        public string $store,
        public string $key,
        public Settable|OwnerRef|null $owner = null,
        public string $locale = '',
    ) {}

    public function ownerRef(): ?OwnerRef
    {
        return $this->owner instanceof Settable ? $this->owner->settingsOwner() : $this->owner;
    }

    /** `store|scope|key|locale@version`, hashed: the ETag of the setting at that version (§16.10). */
    public function etag(int $version): string
    {
        return '"' . sha1($this->store . '|' . Scope::of($this->ownerRef())->value . '|' . $this->key . '|' . new Locale($this->locale)->value . '@' . $version) . '"';
    }
}
