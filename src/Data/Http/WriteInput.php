<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Http;

/** A REST write: address, value, reason, optional `If-Match` ETag and rollback version. */
final readonly class WriteInput
{
    public function __construct(
        public AddressInput $address,
        public mixed $value = null,
        public ?string $reason = null,
        public ?string $ifMatch = null,
        public ?int $toVersion = null,
    ) {}
}
