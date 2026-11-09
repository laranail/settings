<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Illuminate\Http\Request;

/**
 * Where an actor's call came from: client address, user agent, request id and the reason given.
 * Recorded in the audit trail; never carries a setting's value.
 */
final readonly class ActorContext
{
    /** User agents are cut to this length, matching the audit column. */
    public const int USER_AGENT_LIMIT = 255;

    public ?string $userAgent;

    public function __construct(
        public ?string $ip = null,
        ?string $userAgent = null,
        public ?string $requestId = null,
        public ?string $reason = null,
    ) {
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_LIMIT);
    }

    /** The client address, user agent and `X-Request-Id` of a request. */
    public static function fromRequest(Request $request): self
    {
        $requestId = $request->headers->get('X-Request-Id');

        return new self($request->ip(), $request->userAgent(), $requestId === '' ? null : $requestId);
    }

    public function withReason(string $reason): self
    {
        return new self($this->ip, $this->userAgent, $this->requestId, $reason);
    }
}
