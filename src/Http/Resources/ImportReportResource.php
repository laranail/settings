<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;

/**
 * What an import created, updated, left alone and skipped.
 *
 * @property ImportReport $resource
 */
#[PreserveKeys]
final class ImportReportResource extends JsonResource
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
