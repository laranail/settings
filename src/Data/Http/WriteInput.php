<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Http;

/**
 * A REST write: where, the value (for a set), the reason, the `If-Match` ETag the client read
 * (null: unconditional) and, for a rollback, the version to return to.
 */
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
