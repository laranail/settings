<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Stores\Layer;
use Simtabi\Laranail\Settings\Stores\Store;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * A configured store as the API lists it: its name, mode, owners, layers and policies.
 *
 * @property Store $resource
 */
#[PreserveKeys]
final class StoreResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets for its own resources. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'name'      => $this->resource->name->value,
            'mode'      => $this->resource->mode->value,
            'owners'    => $this->resource->owners,
            'layers'    => array_map(static fn (Layer $layer): string => $layer->name, $this->resource->layers),
            'strict'    => $this->resource->strict,
            'inherits'  => $this->resource->inherits,
            'fallback'  => $this->resource->fallback?->value,
            'approvals' => $this->resource->approvalKeys,
        ];
    }
}
