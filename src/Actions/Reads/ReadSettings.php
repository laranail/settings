<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\SettingView;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Services\Resolution\ScopeKeys;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/**
 * Read a page of a scope's settings in key order, leaving out keys the actor may not view.
 * `$after` is the last key of the previous page.
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
        $keys = new ScopeKeys($this->definitions)->keys($store, Scope::of($scope->ownerRef()));
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
