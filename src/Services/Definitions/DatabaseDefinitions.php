<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Definitions;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Services\Database\DefinitionsRepository;

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

        if (! $this->enabled || ! $database instanceof DatabaseDriver || ! $database->shape()->definitionsTable) {
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

        if (! $database instanceof DatabaseDriver || ! $database->shape()->definitionsTable) {
            return null;
        }

        return ($this->loaded[$store->name->value] ??= $this->load($store, $database))[$key->value] ?? null;
    }

    /** @return array<string, Definition> */
    private function load(Store $store, DatabaseDriver $database): array
    {
        $rows = new DefinitionsRepository($database, $this->encoder)->runtimeRows($store->name);
        $translations = new Translations($database->shape()->translations);
        $definitions = [];

        foreach ($rows as $row) {
            $definition = $this->definition($store, $row, $translations);
            $definitions[$definition->key->value] = $definition;
        }

        return $definitions;
    }

    /** @param array<array-key, mixed> $row */
    private function definition(Store $store, array $row, Translations $translations): Definition
    {
        $text = static fn (string $column): ?string => is_string($row[$column] ?? null) && $row[$column] !== '' ? $row[$column] : null;
        $flag = static fn (string $column): bool => DatabaseValues::boolean($row[$column] ?? false);
        $key = new Key($text('key') ?? throw new InvalidDefinition('A definitions row has no key.'));
        $type = $text('type');

        if ($type !== null && ! $this->casters->has($type)) {
            throw new InvalidDefinition(sprintf('Definitions table: setting [%s] uses unknown type [%s]; register a caster for it.', $key, $type));
        }

        $default = $text('default');
        $inherits = $row['inherits'] ?? null;

        return new Definition(
            store: $store->name,
            key: $key,
            type: $type,
            default: $default === null ? null : $this->encoder->decode($key, $default),
            hasDefault: $default !== null,
            rules: $this->rules($text('rules')),
            encrypted: $flag('is_encrypted'),
            public: $flag('is_public'),
            locked: $flag('is_locked'),
            inherits: $inherits === null ? null : DatabaseValues::boolean($inherits),
            overridableBy: Json::strings($text('overridable_by')),
            label: $translations->nullableText($text('label')),
            description: $translations->nullableText($text('description')),
            category: $text('category_slug'),
            group: $text('group'),
        );
    }

    /** @return list<string> the stored rules whose name is allowed */
    private function rules(?string $json): array
    {
        return array_values(array_filter(
            Json::strings($json),
            fn (string $rule): bool => in_array(explode(':', $rule, 2)[0], $this->allowedRules, true),
        ));
    }
}
