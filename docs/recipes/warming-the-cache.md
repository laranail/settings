# Warm the cache after a deploy

Load the global settings into the cache before traffic arrives, and reset it after changing rows by hand.

## After a deploy

Run the warm command once the new release's migrations have run:

```bash
php artisan migrate --force
php artisan laranail::settings.warm
```

It loads the global scope of every cached store (one query per store when it is not cached
already, none when it is) and prints how many records each store holds:

```text
+----------+----------------+
| Store    | Records cached |
+----------+----------------+
| settings | 42             |
+----------+----------------+
```

`--store=settings` (repeatable) warms only the named stores. Owners' settings are cached on their
first read; to warm one ahead of time, call `Settings::for($owner)->warmCache()`.

## After changing rows past the API

A raw query, a data migration or an Eloquent save on `Models\Setting` does not invalidate the
cache. Reset it, then warm it again:

```bash
php artisan laranail::settings.clear-cache
php artisan laranail::settings.warm
```

`--store=settings` resets only the named stores. Either way only cached copies are reset, for
every tenant; stored values and the application's other cache entries are untouched.

## In a tenant loop

The warm command warms the central (no tenant) scope. With column-mode tenancy, warm each tenant
from inside its context:

```php
use Simtabi\Laranail\Settings\Facades\Settings;

Tenant::all()->runForEach(static function (): void {
    Settings::asSystem('deploy warm-up')->warmCache();
});
```

See [Caching](../tools/caching.md) for how bags are keyed and invalidated.

---

[← Docs index](../../README.md#documentation)
