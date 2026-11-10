# Seed the default categories

Create the shipped set of system categories with `db:seed`, change the set, or seed each tenant.

```bash
php artisan db:seed
```

The package registers `DefaultCategoriesSeeder` with the application's `db:seed`, so
`DatabaseSeeder` does not name it. To run it alone:

```bash
php artisan db:seed --class="Simtabi\Laranail\Settings\Database\Seeders\DefaultCategoriesSeeder"
```

It creates general, appearance, localisation, mail, notifications and security as system
categories (`is_system`) in the default store, through the settings API as the system actor, so
each is validated and audited. A slug the store already has is left exactly as it is, so seeding
again changes nothing.

## Your own set

```php
// config/laranail/settings.php
'seed' => [
    'enabled'    => true,
    'stores'     => ['settings', 'billing'],
    'categories' => [
        'mail' => 'Mail',
        'smtp' => ['title' => 'SMTP', 'parent' => 'mail', 'icon' => 'server'],
    ],
],
```

Parents are created before their children whatever the order; the listed order is the display
order. A parent must be in the same list. `enabled => false` (or `LARANAIL_SETTINGS_SEED=false`)
switches it off. Categories carry no secrets, and the seeder writes no setting values.

## Per tenant

In column-mode [tenancy](../tools/tenancy.md) the seeder seeds the current tenant, and outside a
tenant the central rows. Run it once per tenant with your tenancy package's seed command (for
stancl/tenancy, `php artisan tenants:seed --class=...`).

---

[← Docs index](../../README.md#documentation)
