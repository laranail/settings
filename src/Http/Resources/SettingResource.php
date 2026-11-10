<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\SettingView;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A setting as the API returns it. A value the actor may not see is left out (`redacted` true),
 * never masked; `version` is the version of the addressed row (0: it has none) and backs the ETag.
 *
 * @property SettingView $resource
 */
final class SettingResource extends JsonResource
{
    public function __construct(SettingView $view)
    {
        parent::__construct($view);
    }

    /** @return array<string, mixed> */
    public static function fields(SettingView $view): array
    {
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

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        return self::fields($this->resource);
    }
}
