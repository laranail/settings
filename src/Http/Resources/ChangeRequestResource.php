<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * A change request without its proposed payload, which may be a secret.
 *
 * @property ChangeRequest $resource
 */
#[PreserveKeys]
final class ChangeRequestResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
