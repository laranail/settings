<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** A setting as the API returns it; `version` is 0 when the scope has no row of its own. */
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
