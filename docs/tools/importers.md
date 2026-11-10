# Importers

Three importers read another package's settings into an export document (G-43), which the normal [import](import-export.md) then writes. None of those packages is needed.

## Shipped importers

| Name | Reads | Source |
|---|---|---|
| `spatie` | spatie/laravel-settings' table (`group`, `name`, `payload`, `locked`): each row becomes the global key `<group>.<name>`, its JSON payload decoded, its lock kept for a trusted import | `<connection>` or `<connection>:<table>` (default table `settings`) |
| `toolkit` | laranail/toolkit's JSON `SettingsStore` file, flattened (`{"mail": {"host": …}}` is `mail.host`); a list stays one value | the file's absolute path |
| `akaunting` | akaunting/laravel-setting's database table (`key`, `value`); rows its extra columns scope to a model or company are skipped | `<connection>` or `<connection>:<table>` |

Keys are lower-cased and `-` and spaces become `_`; a key that still is not valid fails the import with `InvalidImport` naming it.

```bash
php artisan laranail::settings.import legacy:settings --from=spatie            # a dry run
php artisan laranail::settings.import legacy:settings --from=spatie --apply --trusted
php artisan laranail::settings.import /var/www/storage/app/laranail/settings.json --from=toolkit --apply
```

spatie/laravel-settings and this package both default to a table named `settings`. To read spatie's from the same database, point this package at other names (`tables.prefix` or `stores.settings.table`) before installing, or read spatie's table from another connection.

Values spatie stored encrypted arrive as spatie's ciphertext: define those keys `encrypted()` here and set them again.

## Your own importer

Implement `Simtabi\Laranail\Settings\Contracts\Importer` and name it in `importers`:

```php
final readonly class LegacyIniImporter implements Importer
{
    public function read(string $source, StoreName $store): ExportDocument
    {
        $settings = [];

        foreach (parse_ini_file($source) ?: [] as $key => $value) {
            $settings[] = new ExportedSetting(Scope::global(), new Key($key), Locale::none(), $value);
        }

        return new ExportDocument($store->value, settings: $settings, mode: ExportMode::Plaintext, source: 'ini');
    }
}
```

```php
'importers' => [
    'ini' => App\Settings\LegacyIniImporter::class,
],
```

An importer only reads. It is built from the container, so it can take its dependencies in its constructor.

---

[← Docs index](../../README.md#documentation)
