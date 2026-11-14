<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Logging;

use Simtabi\Laranail\Settings\Data\Actor;

/**
 * The context every operational log entry carries: store, key, scope, actor and channel, in that
 * order, followed by anything specific to the entry. Never a value.
 */
final class LogContext
{
    /** @var list<string> */
    public const array KEYS = ['store', 'key', 'scope', 'actor', 'channel'];

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function of(
        ?string $store = null,
        ?string $key = null,
        ?string $scope = null,
        ?Actor $actor = null,
        ?string $channel = null,
        array $extra = [],
    ): array {
        return [
            'store'   => $store,
            'key'     => $key,
            'scope'   => $scope,
            'actor'   => $actor?->toArray(),
            'channel' => $channel ?? $actor?->channel->value,
            ...$extra,
        ];
    }

    /**
     * The given context with the five standard keys first, null where the entry has none.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public static function normalise(array $context): array
    {
        return [...array_fill_keys(self::KEYS, null), ...$context];
    }
}
