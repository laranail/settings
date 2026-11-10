<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database\Factories;

use Illuminate\Support\Str;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\Settings\Models\SettingDefinition;

/**
 * Rows of the definitions table (`definitions.table`). `default` holds the encoded default, as
 * the definitions sync writes it.
 *
 * @extends Factory<SettingDefinition>
 */
final class SettingDefinitionFactory extends Factory
{
    protected $model = SettingDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = 'test.' . Str::lower(Str::random(12));

        return [
            'store'     => ModelContext::defaultStore()->value,
            'key'       => $key,
            'slug'      => str_replace('.', '-', $key),
            'group'     => 'test',
            'type'      => 'string',
            'default'   => null,
            'is_system' => false,
        ];
    }

    public function forStore(string $store): static
    {
        return $this->state(['store' => $store]);
    }

    public function key(string $key): static
    {
        return $this->state(['key' => $key, 'slug' => str_replace('.', '-', $key), 'group' => explode('.', $key, 2)[0]]);
    }

    public function system(): static
    {
        return $this->state(['is_system' => true]);
    }
}
