<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/**
 * A store export, the same document the console writes.
 *
 * @property ExportDocument $resource
 */
#[PreserveKeys]
final class ExportDocumentResource extends JsonResource
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
