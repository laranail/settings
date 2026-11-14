<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\SettingView;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * A setting as the API returns it. A value the actor may not see is left out and `redacted` set;
 * `version` (0 when no row exists) backs the ETag.
 *
 * @property SettingView $resource
 */
#[PreserveKeys]
final class SettingResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $fields = [
            'store'    => $view->address->store,
            'scope'    => Scope::of($view->address->ownerRef())->value,
            'key'      => $view->address->key,
            'locale'   => new Locale($view->address->locale)->value,
            'found'    => $view->found,
            'layer'    => $view->layer,
            'from'     => $view->from,
            'version'  => $view->version,
            'redacted' => $view->redacted,
        ];

        if (! $view->redacted) {
            $fields['value'] = $view->value;
        }

        return $fields;
    }
}
