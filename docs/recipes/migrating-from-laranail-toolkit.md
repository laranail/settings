# Migrate from laranail/toolkit's settings store

Move the values in toolkit's JSON `SettingsStore` file into this package, from a local path or any filesystem disk.

## 1. Dry run

```bash
php artisan laranail::settings.import --from=toolkit
```

With no source, the importer reads toolkit's own file: the disk and path in `laranail.toolkit.settings` (`LARANAIL_SETTINGS_DISK`, `LARANAIL_SETTINGS_PATH`) when that config is loaded, otherwise `laranail/settings.json` on the `local` disk. Toolkit does not have to be installed. To name the file yourself:

```bash
php artisan laranail::settings.import /var/www/storage/app/laranail/settings.json --from=toolkit   # a local path
php artisan laranail::settings.import laranail/settings.json --from=toolkit --disk=s3             # a path on a disk
```

Nested objects become dot keys (`{"mail": {"host": …}}` is `mail.host`) and a list stays one value.

## 2. Fix collisions, if any

Keys are lower-cased and `-` and spaces become `_`. When two keys land on the same one, for example `Mail-Host` and `mail_host`, or a nested `mail.host` beside a literal `"mail.host"`, the import fails before writing anything and names every group:

```text
toolkit: 1 group of source keys collide after normalising; nothing was imported: [Mail-Host, mail_host] -> mail_host.
```

Keep one key in each group in toolkit's file, then run the dry run again.

## 3. Apply

```bash
php artisan laranail::settings.import --from=toolkit --apply
```

Each value goes through the write pipeline, in one transaction. Then replace `Toolkit::settings()->get('mail.host')` with `settings()->get('mail.host')`.

See [Importers](../tools/importers.md).

---

[← Docs index](../../README.md#documentation)
