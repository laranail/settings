<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Contracts\Settable;

/** A setting's address: store, key, owner or global, and locale. */
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

    /** The ETag of the setting at a version. */
    public function etag(int $version): string
    {
        return '"' . sha1($this->store . '|' . Scope::of($this->ownerRef())->value . '|' . $this->key . '|' . new Locale($this->locale)->value . '@' . $version) . '"';
    }
}
