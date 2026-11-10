<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Simtabi\Laranail\Settings\Resolution\Explanation;

/** How a read resolved: the steps tried and the answer, its value left out when redacted. */
final readonly class ExplanationResource
{
    /** @return array<string, mixed> */
    public static function fields(Explanation $explanation): array
    {
        $lookup = $explanation->lookup;
        $fields = [
            'found'    => $lookup->isFound(),
            'layer'    => $lookup->layer(),
            'from'     => $lookup->scope()?->value,
            'redacted' => $lookup->isRedacted(),
            'steps'    => $explanation->steps,
        ];

        if ($lookup->isFound() && ! $lookup->isRedacted()) {
            $fields['value'] = $lookup->value();
        }

        return $fields;
    }
}
