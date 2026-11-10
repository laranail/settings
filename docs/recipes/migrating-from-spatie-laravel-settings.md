# Migrate from spatie/laravel-settings

Move spatie/laravel-settings' values into this package, and keep typed classes.

## 1. Install beside it

Both packages default to a table named `settings`. Install this one under other names first:

```php
// config/laranail/settings.php
'tables' => ['prefix' => 'app_'],    // app_settings, app_settings_categories, …
```

```bash
php artisan laranail::settings.install
php artisan migrate
```

## 2. Import

```bash
php artisan laranail::settings.import :settings --from=spatie            # a dry run: read the report
php artisan laranail::settings.import :settings --from=spatie --apply --trusted
```

`:settings` is spatie's table on the default connection (`<connection>:<table>`). Each row becomes the key `<group>.<name>`; `--trusted` keeps spatie's locks. Values spatie stored encrypted arrive as spatie's ciphertext: define those keys `encrypted()` here and set them again.

## 3. Keep the typed classes

A spatie class maps to a typed class with the same group:

```php
// before: class GeneralSettings extends Spatie\LaravelSettings\Settings { public string $site_name; public static function group(): string { return 'general'; } }

#[Group('general')]
final class GeneralSettings extends TypedSettings
{
    #[Key('general.site_name')]
    public string $siteName = 'Acme';
}
```

Properties map to snake-cased keys by default (`siteName` is `general.site_name`), so `#[Key]` is only needed where the names differ. Replace `GeneralSettings::class` injections with the new class (resolved from the container, it loads itself) and `$settings->save()` keeps working.

spatie's settings migrations become [settings migrations](../tools/typed-settings.md#settings-migrations-g-17). When nothing reads spatie's table any more, drop it.

---

[← Docs index](../../README.md#documentation)
