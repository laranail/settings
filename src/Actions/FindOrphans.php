<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * Find settings whose owner no longer exists, such as rows left by bulk deletes. Soft-deleted
 * owners keep their settings, and aliases missing from the morph map are reported, not guessed.
 */
final readonly class FindOrphans
{
    public function __construct(
        private StoreRegistry $stores,
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
                $model = Relation::getMorphedModel($alias);

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
