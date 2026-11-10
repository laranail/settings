<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Tenancy\NullTenantDiscriminator;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;

/*
 * Amendment B: column-mode tenancy. One database; every values, categories and audit row carries
 * the current tenant and every query is limited to it, so two tenants writing the same key never
 * see each other's value, and '' holds the central rows.
 */

/** A tenant the test switches by assigning `$tenant->id`. */
function switchableTenant(): object
{
    $tenant = new class
    {
        public ?string $id = null;
    };

    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): ?string => $tenant->id));

    return $tenant;
}

it('adds the tenant column and puts it first in the identity index', function (): void {
    useDatabase(['tenancy.mode' => 'column']);

    $identity = collect(Schema::getIndexes('settings'))->first(static fn (array $index): bool => str_ends_with($index['name'], '_identity_unique'));
    $categories = collect(Schema::getIndexes('settings_categories'))->first(static fn (array $index): bool => str_ends_with($index['name'], '_store_slug_unique'));

    expect(Schema::hasColumn('settings', 'tenant'))->toBeTrue()
        ->and(Schema::hasColumn('settings_categories', 'tenant'))->toBeTrue()
        ->and(Schema::hasColumn('settings_audit', 'tenant'))->toBeTrue()
        ->and($identity['columns'] ?? null)->toBe(['tenant', 'scope', 'key', 'locale'])
        ->and($categories['columns'] ?? null)->toBe(['tenant', 'store', 'slug']);
});

it('has no tenant column in the other modes', function (string $mode): void {
    useDatabase(['tenancy.mode' => $mode]);

    expect(Schema::hasColumn('settings', 'tenant'))->toBeFalse()
        ->and(Schema::hasColumn('settings_categories', 'tenant'))->toBeFalse()
        ->and(Schema::hasColumn('settings_audit', 'tenant'))->toBeFalse();
})->with(['none', 'database']);

it('keeps each tenant\'s values, categories and audit trail apart', function (): void {
    useDatabase(['tenancy.mode' => 'column', 'stores.settings.owners' => ['user']]);
    $tenant = switchableTenant();

    $tenant->id = 'acme';
    settings()->set('site.name', 'Acme');
    settings()->for(new OwnerRef('user', 1))->set('ui.theme', 'dark');
    settings()->categories()->create(new NewCategory('mail', 'Acme mail'));

    $tenant->id = 'globex';
    settings()->set('site.name', 'Globex');
    settings()->categories()->create(new NewCategory('mail', 'Globex mail'));

    $tenant->id = null;
    settings()->set('site.name', 'Central');

    expect(settings()->get('site.name'))->toBe('Central')
        ->and(settings()->for(new OwnerRef('user', 1))->get('ui.theme'))->toBeNull()
        ->and(settings()->categories()->all())->toBe([])
        ->and(DB::table('settings')->orderBy('tenant')->pluck('tenant')->all())->toBe(['', 'acme', 'acme', 'globex']);

    $tenant->id = 'acme';

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->for(new OwnerRef('user', 1))->get('ui.theme'))->toBe('dark')
        ->and(settings()->categories()->get('mail')->title)->toBe('Acme mail')
        ->and(settings()->audit()->key('site.name')->get())->toHaveCount(1)
        ->and(DB::table('settings_audit')->where('key', 'site.name')->count())->toBe(3);

    $tenant->id = 'globex';

    expect(settings()->get('site.name'))->toBe('Globex')
        ->and(settings()->categories()->get('mail')->title)->toBe('Globex mail');

    settings()->forget('site.name');
    $tenant->id = 'acme';

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('never answers one tenant\'s read from another tenant\'s preload in the same request', function (): void {
    useDatabase(['tenancy.mode' => 'column', 'stores.settings.owners' => ['user']]);
    $tenant = switchableTenant();
    $owner = new OwnerRef('user', 7);

    $tenant->id = 'acme';
    settings()->for($owner)->set('ui.theme', 'dark');
    settings()->preload([$owner]);

    $tenant->id = 'globex';

    expect(settings()->for($owner)->get('ui.theme'))->toBeNull();

    $tenant->id = 'acme';

    expect(settings()->for($owner)->get('ui.theme'))->toBe('dark');
});

it('scopes and stamps the models by tenant', function (): void {
    useDatabase(['tenancy.mode' => 'column']);
    $tenant = switchableTenant();

    $tenant->id = 'acme';
    $category = SettingCategory::factory()->create(['slug' => 'billing']);

    $tenant->id = 'globex';

    expect($category->tenant)->toBe('acme')
        ->and(ModelContext::tenant())->toBe('globex')
        ->and(SettingCategory::query()->count())->toBe(0);

    $tenant->id = 'acme';

    expect(SettingCategory::query()->pluck('slug')->all())->toBe(['billing']);
});

it('picks the discriminator from configuration', function (): void {
    $resolve = static function (mixed $driver): TenantDiscriminator {
        config()->set('laranail.settings.tenancy.discriminator', $driver);
        app()->forgetScopedInstances();

        return app(TenantDiscriminator::class);
    };

    expect($resolve('auto'))->toBeInstanceOf(NullTenantDiscriminator::class)
        ->and($resolve('null'))->toBeInstanceOf(NullTenantDiscriminator::class)
        ->and($resolve(null))->toBeInstanceOf(NullTenantDiscriminator::class)
        ->and($resolve(NullTenantDiscriminator::class))->toBeInstanceOf(NullTenantDiscriminator::class)
        ->and($resolve('spatie')->current())->toBeNull()
        ->and(fn (): TenantDiscriminator => $resolve('stancl'))->toThrow(InvalidConfiguration::class, 'needs stancl/tenancy')
        ->and(fn (): TenantDiscriminator => $resolve('nonsense'))->toThrow(InvalidConfiguration::class, 'laranail.settings.tenancy.discriminator');

    // spatie/laravel-multitenancy keeps the current tenant in the container.
    app()->instance('currentTenant', new class
    {
        public function getKey(): int
        {
            return 42;
        }
    });

    expect($resolve('spatie')->current())->toBe('42');
});

it('refuses an unknown tenancy mode', function (): void {
    config()->set('laranail.settings.tenancy.mode', 'schema');
    app()->forgetScopedInstances();

    expect(fn () => app(SchemaShapeRepository::class)->configured())
        ->toThrow(InvalidConfiguration::class, 'laranail.settings.tenancy.mode');
});
