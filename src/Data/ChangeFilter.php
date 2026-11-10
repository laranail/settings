<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Enums\ChangeStatus;

/** Which change requests to list: one store, optionally one status, scope, key or id. */
final readonly class ChangeFilter
{
    public function __construct(
        public StoreName $store,
        public ?ChangeStatus $status = null,
        public ?Scope $scope = null,
        public ?Key $key = null,
        public ?string $id = null,
    ) {}

    /** A copy with the named conditions replaced. */
    public function with(?ChangeStatus $status = null, ?Scope $scope = null, ?Key $key = null, ?string $id = null): self
    {
        return new self($this->store, $status ?? $this->status, $scope ?? $this->scope, $key ?? $this->key, $id ?? $this->id);
    }
}
