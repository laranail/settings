<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Database;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Support\DatabaseValues;

/**
 * The definitions table on one connection (D-6, §4.4): syncing code and config definitions in as
 * `is_system` rows, and reading the rows edited at runtime. Definitions are shared by every
 * tenant, so nothing here filters on the tenant column.
 */
final readonly class DefinitionsRepository
{
    public function __construct(
        private DatabaseDriver $database,
        private ValueEncoder $encoder,
    ) {}

    /**
     * The runtime (not `is_system`) rows of a store, each with its category's slug.
     *
     * @return list<array<array-key, mixed>>
     */
    public function runtimeRows(StoreName $store): array
    {
        $database = $this->database;
        $names = $database->names();

        return array_values($database->attempt($store, static fn (): array => $database->connection()
            ->table($names->definitions() . ' as d')
            ->leftJoin($names->categories() . ' as c', 'c.id', '=', 'd.category_id')
            ->where('d.store', $store->value)
            ->where('d.is_system', false)
            ->get(['d.*', 'c.slug as category_slug'])
            ->map(static fn (object $row): array => (array) $row)
            ->all()));
    }

    /**
     * Upsert a store's code and config definitions as system rows and remove system rows no
     * definition covers any more; a runtime row sharing a key is a conflict and left alone.
     *
     * @param list<Definition> $definitions
     *
     * @return array{synced: int, removed: int, conflicts: list<string>}
     */
    public function sync(StoreName $store, array $definitions): array
    {
        $database = $this->database;
        $table = static fn () => $database->connection()->table($database->names()->definitions())->where('store', $store->value);
        $now = CarbonImmutable::now();
        $translations = new Translations($this->database->shape()->translations);
        $synced = 0;
        $conflicts = [];
        $keys = [];

        foreach ($definitions as $definition) {
            $keys[] = $definition->key->value;
            $row = $table()->where('key', $definition->key->value)->first(['is_system']);

            if ($row !== null && ! DatabaseValues::boolean($row->is_system ?? false)) {
                $conflicts[] = $store->value . '.' . $definition->key->value;

                continue;
            }

            $columns = $this->columns($store, $definition, $translations) + ['updated_at' => $now];

            if ($row === null) {
                $table()->insert($columns + DatabaseDriver::newId($this->database->shape()->ids) + [
                    'store'      => $store->value,
                    'key'        => $definition->key->value,
                    'is_system'  => true,
                    'created_at' => $now,
                ]);
            } else {
                $table()->where('key', $definition->key->value)->update($columns);
            }

            $synced++;
        }

        $removed = $table()->where('is_system', true)->whereNotIn('key', $keys === [] ? [''] : $keys)->delete();

        return ['synced' => $synced, 'removed' => $removed, 'conflicts' => $conflicts];
    }

    /** @return array<string, mixed> */
    private function columns(StoreName $store, Definition $definition, Translations $translations): array
    {
        $category = null;

        if ($definition->category !== null) {
            $categories = $this->database->connection()->table($this->database->names()->categories());

            // Definitions are shared by every tenant, so they link to the central categories ('').
            if ($this->database->shape()->tenancy->hasColumn()) {
                $categories->where('tenant', '');
            }

            $category = $categories->where('store', $store->value)->where('slug', $definition->category)->value('id');
        }

        return [
            'slug'        => $definition->key->slug(),
            'group'       => $definition->group(),
            'category_id' => is_int($category) || is_string($category) ? $category : null,
            'type'        => $definition->type,
            // An encrypted setting's default stays in code: the table must not hold a readable copy.
            'default'        => $definition->hasDefault && ! $definition->encrypted ? $this->encoder->encode($definition->key, $definition->default) : null,
            'label'          => $translations->storeNullable($definition->label),
            'description'    => $translations->storeNullable($definition->description),
            'rules'          => Json::encode($definition->rules, 'A definition'),
            'is_encrypted'   => $definition->encrypted,
            'is_public'      => $definition->public,
            'is_locked'      => $definition->locked,
            'inherits'       => $definition->inherits,
            'overridable_by' => Json::encode($definition->overridableBy, 'A definition'),
        ];
    }
}
