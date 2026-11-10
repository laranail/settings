<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Simtabi\Laranail\Settings\Data\ChangeRequest;

/**
 * A change request (§16.7) without its proposed payload, which may be a secret.
 *
 * @property ChangeRequest $resource
 */
final class ChangeRequestResource extends JsonResource
{
    public function __construct(ChangeRequest $request)
    {
        parent::__construct($request);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
