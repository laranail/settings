<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Simtabi\Laranail\Settings\Stores\Layer;
use Simtabi\Laranail\Settings\Stores\Store;

/** A configured store as the API lists it: its name, mode, owners, layers and policies. */
final readonly class StoreResource
{
    /** @return array<string, mixed> */
    public static function fields(Store $store): array
    {
        return [
            'name'      => $store->name->value,
            'mode'      => $store->mode->value,
            'owners'    => $store->owners,
            'layers'    => array_map(static fn (Layer $layer): string => $layer->name, $store->layers),
            'strict'    => $store->strict,
            'inherits'  => $store->inherits,
            'fallback'  => $store->fallback?->value,
            'approvals' => $store->approvalKeys,
        ];
    }
}
