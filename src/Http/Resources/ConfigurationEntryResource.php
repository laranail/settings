<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;

/**
 * A persisted store or layer entry (§16.11), as the configuration file would hold it.
 *
 * @property ConfigurationEntry $resource
 */
#[PreserveKeys]
final class ConfigurationEntryResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets for its own resources. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        return ['kind' => $this->resource->kind, 'name' => $this->resource->name, 'config' => (object) $this->resource->config];
    }
}
