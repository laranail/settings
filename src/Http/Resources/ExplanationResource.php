<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * How a read resolved: the steps tried and the answer, its value left out when redacted.
 *
 * @property Explanation $resource
 */
#[PreserveKeys]
final class ExplanationResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets for its own resources. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        $lookup = $this->resource->lookup;
        $fields = [
            'found'    => $lookup->isFound(),
            'layer'    => $lookup->layer(),
            'from'     => $lookup->scope()?->value,
            'redacted' => $lookup->isRedacted(),
            'steps'    => $this->resource->steps,
        ];

        if ($lookup->isFound() && ! $lookup->isRedacted()) {
            $fields['value'] = $lookup->value();
        }

        return $fields;
    }
}
