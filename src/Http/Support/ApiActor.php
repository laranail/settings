<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Support;

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;

/**
 * Resolves the actor of a REST request on the `rest` channel. The API never runs as trusted code.
 */
final readonly class ApiActor
{
    public function __construct(
        private AccessGuard $access,
    ) {}

    /** @throws ActorRequired */
    public function of(Request $request, ?string $reason = null): Actor
    {
        $actor = $this->access->resolveActor($request) ?? throw new ActorRequired('The settings API needs an authenticated actor.');
        $actor = $actor->withChannel(Channel::Rest);

        return $reason === null || $reason === '' ? $actor : $actor->withReason($reason);
    }
}
