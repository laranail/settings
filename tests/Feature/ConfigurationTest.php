<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

it('rejects invalid configuration with the offending config path', function (array $overrides, string $message): void {
    foreach ($overrides as $key => $value) {
        config()->set($key, $value);
    }

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'unknown driver'      => [['laranail.settings.layers.runtime.driver' => 'redis'], 'laranail.settings.layers.runtime.driver: unknown driver [redis]'],
    'undefined layer'     => [['laranail.settings.stores.settings.layers' => ['nope']], 'layer [nope] is not defined'],
    'no layers'           => [['laranail.settings.stores.settings.layers' => []], 'at least one layer is required'],
    'alias in two stores' => [['laranail.settings.stores' => [
        'settings' => ['layers' => ['runtime'], 'owners' => ['user']],
        'other'    => ['layers' => ['runtime'], 'owners' => ['user']],
    ]], 'alias [user] is already owned by store [settings]'],
    'missing default store'   => [['laranail.settings.default' => 'missing'], 'laranail.settings.default: store [missing] is not configured'],
    'json path without store' => [['laranail.settings.layers.runtime.path' => 'settings.json'], 'must contain {store}'],
    'public disk'             => [['laranail.settings.layers.runtime.disk' => 'public', 'filesystems.disks.public.visibility' => 'public'], 'is public; use a private disk'],
    'php path traversal'      => [['laranail.settings.layers.shipped.path' => '../defaults.php'], 'must not contain ".."'],
    'invalid store name'      => [['laranail.settings.stores' => ['Bad-Name' => ['layers' => ['runtime']]]], 'Invalid store name'],
    'owners not strings'      => [['laranail.settings.stores.settings.owners' => [1]], 'a list of strings is required'],
]);

it('names an unknown store', function (): void {
    expect(fn (): mixed => settings()->store('nope')->get('site.name'))->toThrow(UnknownStore::class, 'Store [nope] is not configured');
});
