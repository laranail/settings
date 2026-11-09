<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;

/**
 * One audited action. It records who did what to which key and how it ended, and has no field
 * for a value: an audit trail must never become a second copy of the secrets it watches.
 */
final readonly class AuditEntry
{
    /** @param array<string, scalar|null> $meta identifiers only */
    public function __construct(
        public string $id,
        public CarbonImmutable $occurredAt,
        public AuditAction $action,
        public AuditOutcome $outcome,
        public ?string $store,
        public ?string $scope,
        public ?string $key,
        public ?string $locale,
        public ?int $versionFrom,
        public ?int $versionTo,
        public ActorType $actorType,
        public ?string $actorAlias,
        public ?string $actorId,
        public Channel $channel,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
        public ?string $reason,
        public array $meta = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'occurred_at'  => $this->occurredAt->toIso8601String(),
            'action'       => $this->action->value,
            'outcome'      => $this->outcome->value,
            'store'        => $this->store,
            'scope'        => $this->scope,
            'key'          => $this->key,
            'locale'       => $this->locale,
            'version_from' => $this->versionFrom,
            'version_to'   => $this->versionTo,
            'actor_type'   => $this->actorType->value,
            'actor_alias'  => $this->actorAlias,
            'actor_id'     => $this->actorId,
            'channel'      => $this->channel->value,
            'ip'           => $this->ip,
            'user_agent'   => $this->userAgent,
            'request_id'   => $this->requestId,
            'reason'       => $this->reason,
            'meta'         => $this->meta,
        ];
    }
}
