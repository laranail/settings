<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * An audit entry: identifiers, the outcome and the channel, never a value.
 *
 * @property AuditEntry $resource
 */
#[PreserveKeys]
final class AuditEntryResource extends JsonResource
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
