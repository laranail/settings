# Configure the package in code

Set the package up from one class of your own instead of a published config file or `.env` lines.

```php
// app/Settings/AppSettings.php
final class AppSettings implements \Simtabi\Laranail\Settings\Contracts\SettingsConfigurator
{
    public function configure(SettingsSetup $settings): void
    {
        $settings
            ->tables(prefix: 'acme_')
            ->cache(fn (CacheSetup $cache) => $cache->store('redis'))
            ->bootstrap(file_exists(storage_path('app/installing')));
    }
}

// App\Providers\AppServiceProvider::register()
Settings::useConfigurator(\App\Settings\AppSettings::class);
```

Run `php artisan laranail::settings.configurator` to see each key's value and where it came from.
Every setup method, packages and modules contributing their own entries, and the precedence order
are in [Configurator](../tools/configurator.md).

---

[← Docs index](../../README.md#documentation)
