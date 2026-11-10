<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * A setting as an API answers it: where it was found and at which version of the handle's own
 * row (0: no row of its own), and its value unless the actor may not see it (`redacted`).
 */
final readonly class SettingView
{
    public function __construct(
        public SettingAddress $address,
        public bool $found,
        public mixed $value,
        public ?string $layer,
        public ?string $from,
        public int $version,
        public bool $redacted = false,
    ) {}

    public function etag(): string
    {
        return $this->address->etag($this->version);
    }
}
