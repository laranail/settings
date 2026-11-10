<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Support;

use Illuminate\Http\JsonResponse;

/** A cursor-paginated list (§16.10): `{"data": [...], "meta": {"next_cursor": …}}`. */
final readonly class ListResponse
{
    /** @param list<array<string, mixed>> $items */
    public static function make(array $items, ?string $nextCursor = null): JsonResponse
    {
        return new JsonResponse(['data' => $items, 'meta' => ['next_cursor' => $nextCursor]]);
    }
}
