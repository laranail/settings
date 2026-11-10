<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\Channel;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Enums\ActorType;

/**
 * Who is acting and through which channel. The authenticatable is kept for authorizers but never
 * serialised; a queued actor keeps only its type, alias and id.
 */
final readonly class Actor
{
    /**
     * @param list<string>|null $abilities token abilities; null means the actor is not limited by a token
     */
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
        public ?string $alias = null,
        public ?Authenticatable $user = null,
        public Channel $channel = Channel::Php,
        public ?array $abilities = null,
        public ?CarbonImmutable $verifiedAt = null,
        public ActorContext $context = new ActorContext,
    ) {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [
            'type'       => $this->type->value,
            'id'         => $this->id,
            'alias'      => $this->alias,
            'channel'    => $this->channel->value,
            'abilities'  => $this->abilities,
            'verifiedAt' => $this->verifiedAt?->toIso8601String(),
            'context'    => [$this->context->ip, $this->context->userAgent, $this->context->requestId, $this->context->reason],
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $string = static fn (mixed $value): ?string => is_string($value) ? $value : null;
        $context = is_array($data['context'] ?? null) ? array_values($data['context']) : [];
        $abilities = is_array($data['abilities'] ?? null) ? array_values(array_filter($data['abilities'], is_string(...))) : null;
        $verifiedAt = $string($data['verifiedAt'] ?? null);

        $this->type = ActorType::from((string) $string($data['type'] ?? null));
        $this->id = $string($data['id'] ?? null);
        $this->alias = $string($data['alias'] ?? null);
        $this->user = null;
        $this->channel = Channel::from((string) $string($data['channel'] ?? null));
        $this->abilities = $abilities;
        $this->verifiedAt = $verifiedAt === null ? null : CarbonImmutable::parse($verifiedAt);
        $this->context = new ActorContext(
            $string($context[0] ?? null),
            $string($context[1] ?? null),
            $string($context[2] ?? null),
            $string($context[3] ?? null),
        );
    }

    /**
     * An actor for a user; passing token abilities makes it a token actor.
     *
     * @param list<string>|null $abilities
     */
    public static function user(
        Authenticatable $user,
        Channel $channel = Channel::Php,
        ?array $abilities = null,
        ActorContext $context = new ActorContext,
        ?CarbonImmutable $verifiedAt = null,
    ): self {
        $identifier = $user->getAuthIdentifier();

        return new self(
            type: $abilities === null ? ActorType::User : ActorType::Token,
            id: is_scalar($identifier) ? (string) $identifier : null,
            alias: self::aliasOf($user),
            user: $user,
            channel: $channel,
            abilities: $abilities,
            verifiedAt: $verifiedAt,
            context: $context,
        );
    }

    /** Trusted system code, named explicitly so the audit trail can say why it ran. */
    public static function system(?string $reason = null, Channel $channel = Channel::Php): self
    {
        return new self(type: ActorType::System, channel: $channel, context: new ActorContext(reason: $reason));
    }

    public function isSystem(): bool
    {
        return $this->type === ActorType::System;
    }

    /** Whether the actor's token (if any) grants this ability; an actor without a token is not limited. */
    public function tokenAllows(Ability $ability): bool
    {
        if ($this->abilities === null) {
            return true;
        }

        return array_intersect(['*', 'settings:*', $ability->tokenAbility()], $this->abilities) !== [];
    }

    public function withReason(string $reason): self
    {
        return $this->with(context: $this->context->withReason($reason));
    }

    public function withContext(ActorContext $context): self
    {
        return $this->with(context: $context);
    }

    public function withChannel(Channel $channel): self
    {
        return $this->with(channel: $channel);
    }

    public function withVerifiedAt(CarbonImmutable $verifiedAt): self
    {
        return $this->with(verifiedAt: $verifiedAt);
    }

    /**
     * The identifiers the audit trail and logs record.
     *
     * @return array{type: string, alias: string|null, id: string|null, channel: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'alias' => $this->alias, 'id' => $this->id, 'channel' => $this->channel->value];
    }

    private static function aliasOf(Authenticatable $user): string
    {
        // The alias is recorded, never instantiated as a class.
        if (method_exists($user, 'getMorphClass')) {
            $alias = $user->getMorphClass();

            if (is_string($alias)) {
                return $alias;
            }
        }

        return 'user';
    }

    private function with(?Channel $channel = null, ?CarbonImmutable $verifiedAt = null, ?ActorContext $context = null): self
    {
        return new self(
            $this->type,
            $this->id,
            $this->alias,
            $this->user,
            $channel ?? $this->channel,
            $this->abilities,
            $verifiedAt ?? $this->verifiedAt,
            $context ?? $this->context,
        );
    }
}
