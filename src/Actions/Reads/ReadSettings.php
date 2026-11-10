<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\SettingView;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/**
 * A page of an owner's (or the global) settings, for a channel other than PHP: every key the
 * scope holds in any layer of the store, or that a definition of the store names, in key order,
 * each read through the settings handle. A key the actor may not view is left out, not masked
 * (§16.10). `$after` is the last key of the previous page.
 */
final readonly class ReadSettings
{
    public function __construct(
        private SettingTargets $targets,
        private DefinitionRegistry $definitions,
        private ReadSetting $read,
    ) {}

    /** @return array{items: list<SettingView>, next: ?string} */
    public function handle(SettingAddress $scope, ?Actor $actor = null, int $limit = 50, ?string $after = null): array
    {
        $store = $this->targets->store($scope->store);
        $owner = Scope::of($scope->ownerRef());
        $keys = [];

        foreach ($store->load([$owner]) as $records) {
            foreach ($records->all() as $record) {
                $keys[$record->key->value] = true;
            }
        }

        foreach ($this->definitions->all() as $definition) {
            if ($definition->store->value === $store->name->value) {
                $keys[$definition->key->value] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);
        $keys = array_values(array_filter($keys, static fn (string $key): bool => $after === null || strcmp($key, $after) > 0));
        $items = [];
        $last = null;

        foreach ($keys as $key) {
            if (count($items) >= $limit) {
                return ['items' => $items, 'next' => $last];
            }

            $last = $key;

            try {
                $view = $this->read->handle(new SettingAddress($scope->store, $key, $scope->owner, $scope->locale), $actor);
            } catch (AccessDenied) {
                continue;
            }

            if ($view->found && ! $view->redacted) {
                $items[] = $view;
            }
        }

        return ['items' => $items, 'next' => null];
    }
}
