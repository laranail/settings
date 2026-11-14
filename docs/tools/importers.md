# Importers

Three importers read another package's settings into an export document, which the normal [import](import-export.md) then writes. None of those packages is needed.

## Shipped importers

| Name | Reads | Source |
|---|---|---|
| `spatie` | spatie/laravel-settings' table (`group`, `name`, `payload`, `locked`): each row becomes the global key `<group>.<name>`, its JSON payload decoded, its lock kept for a trusted import | `<connection>` or `<connection>:<table>` (default table `settings`) |
| `toolkit` | laranail/toolkit's JSON `SettingsStore` file, flattened (`{"mail": {"host": …}}` is `mail.host`); a list stays one value | the file's absolute path; with `--disk`, the path on that disk; with no source, toolkit's own file |
| `akaunting` | akaunting/laravel-setting's database table (`key`, `value`); rows its extra columns scope to a model or company are skipped | `<connection>` or `<connection>:<table>` |

Keys are lower-cased and `-` and spaces become `_`; a key that still is not valid fails the import with `InvalidImport` naming it.

Two or more source keys that land on the same key (`Mail-Host` and `mail_host`, or toolkit's nested `{"mail": {"host": …}}` beside a literal `"mail.host"`) fail the whole import before anything is written, in a dry run and with `--apply` alike. The `InvalidImport` names every colliding group:

```text
toolkit: 2 groups of source keys collide after normalising; nothing was imported: [A, a] -> a; [b-c, b_c] -> b_c.
```

Rename or remove all but one key in each group at the source, then import again.

```bash
php artisan laranail::settings.import legacy:settings --from=spatie            # a dry run
php artisan laranail::settings.import legacy:settings --from=spatie --apply --trusted
php artisan laranail::settings.import /var/www/storage/app/laranail/settings.json --from=toolkit --apply
php artisan laranail::settings.import backups/settings.json --from=toolkit --disk=s3 --apply
php artisan laranail::settings.import --from=toolkit --apply                    # toolkit's own disk and path
```

## Reading from a disk

The `toolkit` importer reads its file through Laravel's filesystem as well as from a local path. `--disk=<name>` reads the source as a path on that disk. With no source at all it reads toolkit's own file: the disk and path in `laranail.toolkit.settings` (`LARANAIL_SETTINGS_DISK`, `LARANAIL_SETTINGS_PATH`) when that config is loaded, otherwise toolkit's defaults, `laranail/settings.json` on `local`. Toolkit itself is not needed.

In PHP, an importer that reads files implements `Simtabi\Laranail\Settings\Contracts\DiskImporter`:

```php
$importer = app(Importers::class)->get('toolkit');                      // a DiskImporter
$document = $importer->readFromDisk('s3', 'backups/settings.json', new StoreName('settings'));
$document = $importer->readFromDisk(null, null, new StoreName('settings')); // toolkit's own disk and path

settings()->import($document);
```

`spatie` and `akaunting` read a database, so they refuse `--disk`. A disk that is not configured, or a path that is not on it, fails with `InvalidImport`.

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

An importer only reads. It is built from the container, so it can take its dependencies in its constructor. To accept `--disk`, implement `DiskImporter` and its `readFromDisk(?string $disk, ?string $path, StoreName $store)`, reading through an injected `Illuminate\Contracts\Filesystem\Factory`. `ImportedKeys::values()` normalises keys and refuses collisions the way the shipped importers do.

---

[← Docs index](../../README.md#documentation)
