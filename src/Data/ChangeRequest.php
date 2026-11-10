<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;

/**
 * A write waiting for approval. `payload` is stored as the values table would store it and is
 * left out of {@see toArray()}; `baseVersion` is the setting's version when the request opened.
 */
final readonly class ChangeRequest
{
    /** @param list<string> $approvals */
    public function __construct(
        public string $id,
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public ChangeOperation $operation,
        public ?string $payload,
        public ?string $type,
        public bool $encrypted,
        public int $baseVersion,
        public ChangeStatus $status,
        public ActorType $requestedByType,
        public ?string $requestedByAlias,
        public ?string $requestedById,
        public array $approvals = [],
        public ?ActorType $decidedByType = null,
        public ?string $decidedByAlias = null,
        public ?string $decidedById = null,
        public ?CarbonImmutable $decidedAt = null,
        public ?string $reason = null,
        public ?string $decisionReason = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /** How an actor is written in `approvals`, and compared with the requester. */
    public static function actorKey(Actor $actor): string
    {
        return $actor->type->value . ':' . ($actor->alias ?? '') . ':' . ($actor->id ?? '');
    }

    public function requesterKey(): string
    {
        return $this->requestedByType->value . ':' . ($this->requestedByAlias ?? '') . ':' . ($this->requestedById ?? '');
    }

    public function isRequestedBy(Actor $actor): bool
    {
        return ! $actor->isSystem() && self::actorKey($actor) === $this->requesterKey();
    }

    public function isExpired(CarbonImmutable $now): bool
    {
        return $this->expiresAt instanceof CarbonImmutable && $this->expiresAt->lessThanOrEqualTo($now);
    }

    /** The owner the request writes for, or null for the global value. */
    public function owner(): ?OwnerRef
    {
        return Scope::fromString($this->scope)?->owner();
    }

    /**
     * Everything but the payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'store'           => $this->store,
            'scope'           => $this->scope,
            'key'             => $this->key,
            'locale'          => $this->locale,
            'operation'       => $this->operation->value,
            'encrypted'       => $this->encrypted,
            'base_version'    => $this->baseVersion,
            'status'          => $this->status->value,
            'requested_by'    => ['type' => $this->requestedByType->value, 'alias' => $this->requestedByAlias, 'id' => $this->requestedById],
            'approvals'       => $this->approvals,
            'decided_by'      => $this->decidedByType instanceof ActorType ? ['type' => $this->decidedByType->value, 'alias' => $this->decidedByAlias, 'id' => $this->decidedById] : null,
            'decided_at'      => $this->decidedAt?->toIso8601String(),
            'reason'          => $this->reason,
            'decision_reason' => $this->decisionReason,
            'expires_at'      => $this->expiresAt?->toIso8601String(),
            'created_at'      => $this->createdAt?->toIso8601String(),
        ];
    }
}
