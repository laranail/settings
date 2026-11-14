# Set up multi-tenancy

Keep each tenant's settings, categories, audit and history apart, in one shared database or in a database per tenant.

## Choose the mode before installing

```php
// config/laranail/settings.php
'tenancy' => [
    'mode'          => 'column',   // one database: a tenant column on every package table
    'discriminator' => 'auto',     // stancl/tenancy or spatie/laravel-multitenancy when installed
],
```

```bash
php artisan laranail::settings.install
php artisan migrate
```

The migration reads the mode to decide whether to add the `tenant` column, and the runtime reads the same key, so changing it later needs a migration. With `database` mode instead, publish the migration into the tenant migrations folder and let the tenancy package switch connections.

## Tell the package who the tenant is

With stancl/tenancy or spatie/laravel-multitenancy installed, `auto` finds the tenant. Otherwise bind your own:

```php
'tenancy' => ['mode' => 'column', 'discriminator' => App\Settings\TeamTenant::class],
```

```php
final readonly class TeamTenant implements TenantDiscriminator
{
    public function __construct(private CurrentTeam $team) {}

    public function current(): ?string
    {
        return $this->team->id();
    }
}
```

## Use settings as usual

```php
tenancy()->initialize($acme);
$settings->set('site.name', 'Acme');          // stamped with Acme's tenant id

tenancy()->initialize($globex);
$settings->get('site.name');                  // null: Globex has its own rows
```

Reads, writes, the cache and the in-memory preload are all keyed by tenant, so a queue worker or an Octane worker that serves several tenants never mixes them. `''` is the central application's tenant.

## Seed each tenant's categories

```php
tenancy()->runForMultiple(Tenant::all(), fn () => Artisan::call('db:seed', [
    '--class' => DefaultCategoriesSeeder::class,
]));
```

## Per-tenant defaults

Definitions are shared by every tenant; values are not. A tenant inherits the definition default until it writes its own value, so ship defaults in definitions or `settings/defaults.php`, not in seeders.

See [Tenancy](../tools/tenancy.md) for the adapters, the unique keys and the readiness flush on tenant switches.

---

[← Docs index](../../README.md#documentation)
