<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * One suite over every built-in driver. The driver list is DriverType::cases(), not a
 * hand-written array, and the count is asserted so a renamed enum cannot empty the suite.
 */
function driverFor(DriverType $type): Driver
{
    $path = sys_get_temp_dir() . '/laranail-contract-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($path, "<?php return ['site.name' => 'Shipped'];");
    config()->set('contract', ['site' => ['name' => 'Configured']]);

    if ($type === DriverType::Database) {
        useDatabase(stores: ['one', 'two']);
    }

    return app(DriverFactory::class)->make(match ($type) {
        DriverType::Database => new LayerConfig('d', 'database', true),
        DriverType::Array    => new LayerConfig('a', 'array', true),
        DriverType::Json     => new LayerConfig('j', 'json', true, ['disk' => 'local', 'path' => 'contract/{store}.json']),
        DriverType::Php      => new LayerConfig('p', 'php', false, ['path' => $path]),
        DriverType::Config   => new LayerConfig('c', 'config', false, ['prefix' => 'contract']),
    });
}

it('covers every built-in driver', function (): void {
    expect(DriverType::cases())->toHaveCount(5);
});

it('returns only the scopes asked for', function (DriverType $type): void {
    $driver = driverFor($type);
    $store = new StoreName('settings');
    $owner = new OwnerRef('user', 1)->scope();

    if ($driver->isWritable()) {
        $driver->put($store, new Record(Scope::global(), new Key('site.name'), Locale::none(), '"Global"'));
        $driver->put($store, new Record($owner, new Key('site.name'), Locale::none(), '"Owner"'));
    }

    expect($driver->load($store, [Scope::global()])->find(Scope::global(), new Key('site.name'), Locale::none()))->not->toBeNull()
        ->and($driver->load($store, [$owner])->find(Scope::global(), new Key('site.name'), Locale::none()))->toBeNull();
})->with(DriverType::cases());

it('round-trips a record through put, load and forget when writable', function (DriverType $type): void {
    $driver = driverFor($type);
    $store = new StoreName('settings');
    $key = new Key('ui.theme');
    $scope = new OwnerRef('user', 9)->scope();

    if (! $driver->isWritable()) {
        expect(fn () => $driver->put($store, new Record($scope, $key, Locale::none(), '"dark"')))->toThrow(StoreNotWritable::class)
            ->and(fn () => $driver->forget($store, $scope, $key, Locale::none()))->toThrow(StoreNotWritable::class);

        return;
    }

    $driver->put($store, new Record($scope, $key, new Locale('fr'), '"sombre"', type: 'string', locked: true, version: 3));
    $loaded = $driver->load($store, [$scope])->find($scope, $key, new Locale('fr'));

    expect($loaded?->payload)->toBe('"sombre"')
        ->and($loaded?->type)->toBe('string')
        ->and($loaded?->locked)->toBeTrue()
        ->and($loaded?->version)->toBe(3);

    $driver->forget($store, $scope, $key, new Locale('fr'));

    expect($driver->load($store, [$scope])->count())->toBe(0);
})->with(DriverType::cases());

it('keeps stores apart in the same driver', function (DriverType $type): void {
    $driver = driverFor($type);

    if (! $driver->isWritable()) {
        expect(true)->toBeTrue();

        return;
    }

    $driver->put(new StoreName('one'), new Record(Scope::global(), new Key('k'), Locale::none(), '1'));

    expect($driver->load(new StoreName('two'), [Scope::global()])->count())->toBe(0);
})->with(DriverType::cases());

it('builds a custom driver registered with a closure and refuses to replace a built-in one', function (): void {
    $factory = app(DriverFactory::class);
    $factory->extend('memory', static fn (LayerConfig $layer): Driver => driverFor(DriverType::Array));

    expect($factory->make(new LayerConfig('m', 'memory', true)))->toBeInstanceOf(Driver::class)
        ->and($factory->names())->toContain('memory', 'json')
        ->and(fn () => $factory->extend('json', static fn (LayerConfig $layer): Driver => driverFor(DriverType::Array)))
        ->toThrow(InvalidConfiguration::class, 'is built in');
});
