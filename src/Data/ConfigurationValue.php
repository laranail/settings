<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * One configuration key the package reads, its value (redacted where it could hold a secret) and
 * where the value came from: `default` (the shipped file), `config` (the application's config),
 * `configurator` (with the owning identity: `app`, a package or a module) or `persisted` (a store
 * or layer saved in the database).
 */
final readonly class ConfigurationValue
{
    public function __construct(
        public string $key,
        public mixed $value,
        public string $source,
        public ?string $owner = null,
    ) {}

    /** @return array{key: string, value: mixed, source: string, owner: string|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value, 'source' => $this->source, 'owner' => $this->owner];
    }
}
