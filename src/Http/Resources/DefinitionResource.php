<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Definition;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * A definition as the API lists it. An encrypted definition's default is left out.
 *
 * @property Definition $resource
 */
#[PreserveKeys]
final class DefinitionResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets for its own resources. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        $definition = $this->resource;
        $fields = [
            'store'                 => $definition->store->value,
            'key'                   => $definition->key->value,
            'group'                 => $definition->group(),
            'category'              => $definition->category,
            'type'                  => $definition->type,
            'has_default'           => $definition->hasDefault && ! $definition->encrypted,
            'rules'                 => $definition->rules,
            'encrypted'             => $definition->encrypted,
            'sensitive'             => $definition->isSensitive(),
            'public'                => $definition->public,
            'locked'                => $definition->locked,
            'localised'             => $definition->localised,
            'inherits'              => $definition->inherits,
            'overridable_by'        => $definition->overridableBy,
            'precedence'            => $definition->precedence->value,
            'label'                 => $definition->label,
            'description'           => $definition->description,
            'requires_verification' => $definition->requiresVerification,
            'requires_approval'     => $definition->requiresApproval,
        ];

        if ($definition->hasDefault && ! $definition->encrypted) {
            $fields['default'] = $definition->default;
        }

        return $fields;
    }
}
