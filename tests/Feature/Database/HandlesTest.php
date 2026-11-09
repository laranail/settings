<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;
use Simtabi\Laranail\Settings\Drivers\BootstrapGatedDriver;

it('flushes one owner\'s values from every writable layer and leaves others alone', function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime']);
    app()->forgetScopedInstances();
    $user = new OwnerRef('user', 1);
    settings()->for($user)->set('ui.theme', 'dark');
    settings()->for(new OwnerRef('user', 2))->set('ui.theme', 'light');
    settings()->set('site.name', 'Acme');

    settings()->for($user)->flush();

    expect(DB::table('settings')->pluck('scope')->sort()->values()->all())->toBe(['global', 'user:2'])
        ->and(fn () => settings()->flush())->toThrow(LogicException::class, 'call for($owner) first');

    // A file-only store removes the owner's records one by one.
    config()->set('laranail.settings.stores.settings.layers', ['runtime', 'shipped']);
    app()->forgetScopedInstances();
    settings()->for($user)->set('ui.theme', 'dark');
    settings()->for($user)->flush();

    expect(settings()->for($user)->has('ui.theme'))->toBeFalse();
});

it('preloads owner references, then answers from memory until a write', function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);
    $users = [new OwnerRef('user', 1), new OwnerRef('user', 2)];
    settings()->for($users[0])->set('ui.theme', 'dark');

    settings()->store('settings')->preload($users);
    DB::enableQueryLog();

    expect(settings()->for($users[0])->get('ui.theme'))->toBe('dark')
        ->and(settings()->for($users[1])->get('ui.theme'))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('keeps a skipped database layer out of reads and writes', function (): void {
    config()->set('database.connections.unmigrated', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('laranail.settings.layers.database.connection', 'unmigrated');
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime']);
    app()->forgetInstance(DriverFactory::class);
    app()->forgetScopedInstances();

    $layer = app(StoreRegistry::class)->default()->layers[0]->driver;
    assert($layer instanceof DatabaseDriver);
    $gate = new BootstrapGatedDriver($layer, new StoreName('settings'), new InstallationEvidence(true));
    $store = new StoreName('settings');

    expect($gate->isActive())->toBeFalse()
        ->and($gate->isWritable())->toBeFalse()
        ->and($gate->database())->toBe($layer)
        ->and($gate->load($store, [Scope::global()])->count())->toBe(0)
        ->and(fn () => $gate->put($store, new Record(Scope::global(), new Key('a'), Locale::none(), '1')))->toThrow(StoreNotWritable::class)
        ->and(fn () => $gate->forget($store, Scope::global(), new Key('a'), Locale::none()))->toThrow(StoreNotWritable::class)
        ->and(app(StoreRegistry::class)->default()->hasDatabaseLayer())->toBeTrue();

    config()->set('laranail.settings.layers.database.connection');
    useDatabase();
    $active = new BootstrapGatedDriver(app(StoreRegistry::class)->default()->database() ?? throw new LogicException, $store, new InstallationEvidence(true));
    $active->put($store, new Record(Scope::global(), new Key('a'), Locale::none(), '1'));

    expect($active->load($store, [Scope::global()])->count())->toBe(1);

    $active->forget($store, Scope::global(), new Key('a'), Locale::none());

    expect($active->load($store, [Scope::global()])->count())->toBe(0);
});

it('reports a promotion as plain data', function (): void {
    expect(new PromotionReport('settings', ['global|a|'], ['global|b|' => 'exists'], 'x.json')->toArray())->toBe([
        'store'    => 'settings',
        'promoted' => ['global|a|'],
        'skipped'  => ['global|b|' => 'exists'],
        'archive'  => 'x.json',
    ]);
});

it('refuses to replay a runtime archive outside the disk, or one that is not there', function (): void {
    useDatabase();

    expect(fn (): PromotionReport => settings()->promote(new PromotionRequest(from: 'missing.json')))->toThrow(DriverFailure::class, 'does not exist');

    Storage::disk('local')->put('broken.json', '{"records": "nope"}');

    expect(fn (): PromotionReport => settings()->promote(new PromotionRequest(from: 'broken.json')))->toThrow(DriverFailure::class, 'no "records" list');
});

it('lets the fake reach the store-level API over its in-memory layers', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    $fake = Settings::fake();

    $fake->for(new OwnerRef('user', 1))->set('ui.theme', 'dark');
    $fake->preload([new OwnerRef('user', 1)]);
    $fake->for(new OwnerRef('user', 1))->flush();

    // The fake has no database layer, so the database-only features say so.
    expect($fake->for(new OwnerRef('user', 1))->has('ui.theme'))->toBeFalse()
        ->and(fn (): array => $fake->categories()->all())->toThrow(StoreNotWritable::class)
        ->and(fn (): PromotionReport => $fake->promote())->toThrow(StoreNotWritable::class);
});
