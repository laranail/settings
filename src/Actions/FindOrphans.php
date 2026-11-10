<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;

/**
 * Find the settings of owners that no longer exist (G-7): rows left by bulk deletes, which fire
 * no model events. For each store's owner aliases it lists the owner scopes in the database
 * layer and asks the aliased model which ids still exist, soft-deleted ones included (a
 * soft-deleted owner keeps its settings for a restore). An alias with no model in the morph map
 * is reported, never guessed at. Read-only; {@see PruneOrphans} removes what it finds.
 */
final readonly class FindOrphans
{
    /** @param Closure(string): ?string $models the model class of a morph alias (the morph map) */
    public function __construct(
        private StoreRegistry $stores,
        private Closure $models,
    ) {}

    /**
     * The orphaned owners per store, and the aliases no model answers for.
     *
     * @return array{orphans: array<string, list<OwnerRef>>, unmapped: list<string>}
     */
    public function handle(): array
    {
        $orphans = [];
        $unmapped = [];

        foreach ($this->stores->all() as $name => $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver) {
                continue;
            }

            foreach ($store->owners as $alias) {
                $model = ($this->models)($alias);

                if ($model === null || ! is_subclass_of($model, Model::class)) {
                    $unmapped[] = $alias;

                    continue;
                }

                $found = $this->orphansOf($store, $database, $alias, $model);

                if ($found !== []) {
                    $orphans[$name] = [...($orphans[$name] ?? []), ...$found];
                }
            }
        }

        return ['orphans' => $orphans, 'unmapped' => array_values(array_unique($unmapped))];
    }

    /**
     * @param class-string<Model> $model
     *
     * @return list<OwnerRef>
     */
    private function orphansOf(Store $store, DatabaseDriver $database, string $alias, string $model): array
    {
        $instance = new $model;
        $integerKeys = $instance->getKeyType() === 'int';
        $orphans = [];

        foreach (array_chunk($database->scopesOf($store->name, $alias), 500) as $scopes) {
            $ids = array_map(static fn (string $scope): string => substr($scope, strlen($alias) + 1), $scopes);
            $keys = $integerKeys ? array_map(intval(...), array_filter($ids, ctype_digit(...))) : $ids;

            $existing = array_map(
                static fn (mixed $key): string => is_scalar($key) ? (string) $key : '',
                $instance->newQueryWithoutScopes()->whereKey($keys)->pluck($instance->getKeyName())->all(),
            );

            foreach (array_diff($ids, $existing) as $id) {
                $orphans[] = new OwnerRef($alias, $id);
            }
        }

        return $orphans;
    }
}
