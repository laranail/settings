<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Definitions;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;

/**
 * The `database` definition source: runtime rows of the definitions table.
 *
 * System rows are skipped, since code is the source of truth for them. Stored rules are filtered
 * through `definitions.allowed_rules`, so a row cannot make the package run arbitrary code.
 */
final class DatabaseDefinitions
{
    /** @var array<string, array<string, Definition>> */
    private array $loaded = [];

    /** @param list<string> $allowedRules rule names a stored rule may use */
    public function __construct(
        private readonly CasterRegistry $casters,
        private readonly ValueEncoder $encoder,
        private readonly array $allowedRules,
        private readonly bool $enabled = true,
    ) {}

    /** Forget the store's loaded rows so the next read sees the latest change. */
    public function forget(Store $store): void
    {
        unset($this->loaded[$store->name->value]);
    }

    /** @return array<string, Definition> every runtime definition of the store, by key */
    public function all(Store $store): array
    {
        $database = $store->database();

        if (! $this->enabled || ! $database instanceof DatabaseDriver) {
            return [];
        }

        return $this->loaded[$store->name->value] ??= $this->load($store, $database);
    }

    public function find(Store $store, Key $key): ?Definition
    {
        if (! $this->enabled) {
            return null;
        }

        $database = $store->database();

        if (! $database instanceof DatabaseDriver) {
            return null;
        }

        return ($this->loaded[$store->name->value] ??= $this->load($store, $database))[$key->value] ?? null;
    }

    /** @return array<string, Definition> */
    private function load(Store $store, DatabaseDriver $database): array
    {
        $rows = $database->attempt($store->name, static fn (): array => SettingDefinition::on($database->connectionName())
            ->with('category')
            ->forStore($store->name)
            ->runtime()
            ->get()
            ->all());
        $definitions = [];

        foreach ($rows as $row) {
            $definition = $this->definition($store, $row);
            $definitions[$definition->key->value] = $definition;
        }

        return $definitions;
    }

    private function definition(Store $store, SettingDefinition $row): Definition
    {
        $key = new Key($row->key);
        $type = $row->type === '' ? null : $row->type;

        if ($type !== null && ! $this->casters->has($type)) {
            throw new InvalidDefinition(sprintf('Definitions table: setting [%s] uses unknown type [%s]; register a caster for it.', $key, $type));
        }

        $default = $row->default === '' ? null : $row->default;

        return new Definition(
            store: $store->name,
            key: $key,
            type: $type,
            default: $default === null ? null : $this->encoder->decode($key, $default),
            hasDefault: $default !== null,
            rules: $this->rules($row->rules ?? []),
            encrypted: $row->is_encrypted,
            public: $row->is_public,
            locked: $row->is_locked,
            inherits: $row->inherits,
            overridableBy: array_values(array_filter($row->overridable_by ?? [], is_string(...))),
            label: Translations::text($row->label),
            description: Translations::text($row->description),
            category: $row->category?->slug,
            group: $row->group === '' ? null : $row->group,
        );
    }

    /**
     * @param array<array-key, mixed> $rules
     *
     * @return list<string> the stored rules whose name is allowed
     */
    private function rules(array $rules): array
    {
        return array_values(array_filter(
            array_values(array_filter($rules, is_string(...))),
            fn (string $rule): bool => in_array(explode(':', $rule, 2)[0], $this->allowedRules, true),
        ));
    }
}
