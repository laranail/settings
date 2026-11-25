<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Package\Tools\Services\Database\SeederRegistry;
use Simtabi\Laranail\Settings\Database\Seeders\DefaultCategoriesSeeder;

/*
 * The default categories (`seed.*`): system rows, created through the settings API as the system
 * actor, and idempotent.
 */

function seedDefaults(): void
{
    Artisan::call('db:seed', ['--class' => DefaultCategoriesSeeder::class, '--force' => true]);
}

/** @return list<string> */
function categorySlugs(?string $store = null): array
{
    $categories = $store === null ? settings()->categories() : settings()->store($store)->categories();

    return array_map(static fn (Category $category): string => $category->slug->value, $categories->all());
}

it('seeds the default categories as system categories of the default store', function (): void {
    useDatabase();

    seedDefaults();

    $general = settings()->categories()->get('general');

    expect(categorySlugs())->toBe(['general', 'appearance', 'localisation', 'mail', 'notifications', 'security'])
        ->and($general->system)->toBeTrue()
        ->and($general->title)->toBe('General')
        ->and($general->icon)->toBe('cog')
        ->and(DB::table('settings_audit')->where('action', 'manage_categories')->where('actor_type', 'system')->count())->toBe(6);
});

it('changes nothing when it runs again, a renamed category included', function (): void {
    useDatabase();
    seedDefaults();
    settings()->categories()->rename('mail', 'Email');
    $before = DB::table('settings_categories')->orderBy('slug')->get(['slug', 'title', 'updated_at'])->all();

    seedDefaults();

    expect(DB::table('settings_categories')->orderBy('slug')->get(['slug', 'title', 'updated_at'])->all())->toEqual($before)
        ->and(settings()->categories()->get('mail')->title)->toBe('Email');
});

it('seeds the configured set into the listed stores, parents first', function (): void {
    useDatabase([
        'stores.billing'  => ['layers' => ['database']],
        'seed.stores'     => ['settings', 'billing'],
        'seed.categories' => [
            'smtp' => ['title' => 'SMTP', 'parent' => 'mail'],
            'mail' => 'Mail',
        ],
    ], stores: ['billing']);

    seedDefaults();

    // Listed order is display order (sort_order); creation order is parents first.
    expect(categorySlugs('billing'))->toBe(['smtp', 'mail'])
        ->and(settings()->store('billing')->categories()->get('smtp')->parent?->value)->toBe('mail')
        ->and(categorySlugs())->toBe(['smtp', 'mail']);
});

it('seeds nothing when switched off', function (): void {
    useDatabase(['seed.enabled' => false]);

    seedDefaults();

    expect(DB::table('settings_categories')->count())->toBe(0);
});

it('refuses a set it cannot seed', function (array $seed, string $message): void {
    useDatabase($seed);

    expect(fn () => app(DefaultCategoriesSeeder::class)->run(settings(), config()))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'no title'       => [['seed.categories' => ['mail' => ['icon' => 'x']]], 'seed.categories.mail'],
    'missing parent' => [['seed.categories' => ['smtp' => ['title' => 'SMTP', 'parent' => 'mail']]], 'smtp'],
    'not a map'      => [['seed.categories' => 'mail'], 'seed.categories'],
    'bad stores'     => [['seed.stores' => [1]], 'seed.stores'],
]);

it('runs with the application\'s db:seed through the package seeder hook', function (): void {
    useDatabase();

    $bundle = app(SeederRegistry::class)->get('laranail/settings:defaults');

    // Registered for db:seed, with foreign keys still checked and events firing.
    expect($bundle?->seeders())->toBe([DefaultCategoriesSeeder::class])
        ->and($bundle?->disablesForeignKeyChecks())->toBeFalse()
        ->and($bundle?->shouldFireEvents())->toBeTrue();
});
