<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Simtabi\Laranail\Settings\Data\Definition;

/** A definition as the API lists it. An encrypted definition's default is left out. */
final readonly class DefinitionResource
{
    /** @return array<string, mixed> */
    public static function fields(Definition $definition): array
    {
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
