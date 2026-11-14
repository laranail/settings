<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\CacheTarget;
use Simtabi\Laranail\Settings\Drivers\CachingDriver;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Tests\Fixtures\Typed\MailSettings;

/*
 * Runtime overrides over the database layer and its cache: an override answers without a query,
 * never reaches the cache, and a typed save() never persists it.
 */

beforeEach(function (): void {
    useDatabase(['stores.settings.owners' => ['user'], 'typed.classes' => [MailSettings::class]]);
});

/** How many queries the callback sends to the default connection. */
function overrideQueriesDuring(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** The raw cached bag of the default store's global scope, or null when nothing is cached. */
function overrideCachedBag(): mixed
{
    $driver = app(StoreRegistry::class)->default()->layers[0]->driver;
    assert($driver instanceof CachingDriver);
    $database = $driver->database();
    $store = new StoreName('settings');
    $cache = app(SettingsCache::class);

    $name = config('laranail.settings.cache.store');

    return Cache::store(is_string($name) ? $name : null)->get($cache->bagKey(new CacheTarget($database->connectionName(), (string) $database->connection()->getDatabaseName(), $store, $database->tableFor($store)), Scope::global()));
}

it('answers ahead of the database layer, and without a query', function (): void {
    settings()->set('mail.from', 'stored@example.test');
    app()->forgetScopedInstances();

    $cold = overrideQueriesDuring(static fn (): mixed => settings()->get('mail.from'));
    app()->forgetScopedInstances();
    settings()->override('mail.from', 'override@example.test');
    $hit = overrideQueriesDuring(static function (): void {
        expect(settings()->get('mail.from'))->toBe('override@example.test')
            ->and(settings()->lookup('mail.from')->layer())->toBe('override');
    });

    expect($cold)->toBeGreaterThan(0)
        ->and($hit)->toBe(0);
});

it('never reaches the cache: the cached bag stays the stored one, and clearing the override returns it', function (): void {
    settings()->set('mail.from', 'stored@example.test');
    app()->forgetScopedInstances();
    expect(settings()->get('mail.from'))->toBe('stored@example.test');
    $bag = overrideCachedBag();

    settings()->override('mail.from', 'override@example.test');

    expect($bag)->not->toBeNull()
        ->and(settings()->get('mail.from'))->toBe('override@example.test')
        ->and(overrideCachedBag())->toBe($bag);

    settings()->forgetOverride('mail.from');

    expect(settings()->get('mail.from'))->toBe('stored@example.test')
        ->and(overrideQueriesDuring(static fn (): mixed => settings()->get('mail.from')))->toBe(0);

    app()->forgetScopedInstances();

    expect(settings()->get('mail.from'))->toBe('stored@example.test')
        ->and(DB::table('settings')->where('key', 'mail.from')->count())->toBe(1);
});

it('lets typed settings load an override, and never saves it', function (): void {
    settings()->set('mail.port', 25);
    settings()->override(['mail.port' => 2525, 'site.name' => 'Override']);

    $mail = MailSettings::load(settings());

    expect($mail->port)->toBe(2525)
        ->and($mail->siteName)->toBe('Override');

    $mail->save();
    $mail->save(settings());
    settings()->clearOverrides();

    expect(settings()->get('mail.port'))->toBe(25)
        ->and(settings()->lookup('site.name')->layer())->toBe('default')
        ->and(DB::table('settings')->where('key', 'site.name')->exists())->toBeFalse();
});

it('saves an overridden typed property once it is changed', function (): void {
    settings()->override('mail.port', 2525);
    $mail = MailSettings::load(settings());

    $mail->port = 587;
    $mail->save();
    settings()->clearOverrides();

    expect(settings()->get('mail.port'))->toBe(587);
});

it('marks an override in laranail::settings.show', function (): void {
    settings()->set('mail.from', 'stored@example.test');
    settings()->override('mail.from', 'override@example.test');

    expect(Artisan::call('laranail::settings.show', ['key' => 'mail.from']))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('override@example.test')
        ->and($output)->toMatch('/Layer\s+\|\s+override/')
        ->and($output)->toContain('held in memory for this request or job, not stored')
        ->and($output)->toContain('settings / global / (none) / override');

    settings()->clearOverrides();
    Artisan::call('laranail::settings.show', ['key' => 'mail.from']);

    expect(Artisan::output())->not->toContain('held in memory');
});
