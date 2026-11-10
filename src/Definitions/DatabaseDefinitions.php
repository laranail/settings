<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Definitions;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Enums\TranslationsMode;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;

/**
 * The `database` definition source (D-6): rows of the optional definitions table that are not
 * `is_system`. `is_system` rows are copies of code and config definitions, kept for UIs, and are
 * skipped: code is the source of truth for those, so an older synced copy can never override a
 * release's new default (§4.4). A row only answers for a key that no code or config definition
 * covers, so it can never loosen a coded setting.
 *
 * Rules stored in a row are filtered through `definitions.allowed_rules`, and a row's type must
 * be a registered caster alias, so a row can never make the package run arbitrary code. Read on
 * first use per store and request (one query), never at boot, and not while the store's
 * database layer is skipped (bootstrap mode).
 *
 * Bound `scoped`.
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

    /**
     * A label as text: itself, or (json mode) the entry for any locale, else the first one.
     *
     * @deprecated 0.1 Use {@see Translations::nullableText()}; this forwards to it and is removed in 1.0.
     */
    public static function localised(?string $stored, bool $json): ?string
    {
        return new Translations($json ? TranslationsMode::Json : TranslationsMode::Lang)->nullableText($stored);
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
        $names = $database->names();
        $rows = $database->attempt($store->name, static fn (): array => $database->connection()
            ->table($names->definitions() . ' as d')
            ->leftJoin($names->categories() . ' as c', 'c.id', '=', 'd.category_id')
            ->where('d.store', $store->name->value)
            ->where('d.is_system', false)
            ->get(['d.*', 'c.slug as category_slug'])
            ->map(static fn (object $row): array => (array) $row)
            ->all());
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
