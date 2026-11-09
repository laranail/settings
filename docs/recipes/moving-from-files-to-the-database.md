# Moving from files to the database

Start an install on the runtime file, then move what was written into the database once it exists.

## During the install

Opt the store into bootstrap mode and give the evidence that an install is running:

```php
'stores' => [
    'settings' => ['layers' => ['database', 'runtime', 'shipped'], 'bootstrap' => true],
],
```

```dotenv
LARANAIL_SETTINGS_BOOTSTRAP=true
```

`laranail/installer-headless` reporting an install in progress counts as evidence too. While the
evidence holds and the database is not ready, the database layer is skipped: writes go to the
runtime file and a `LayerSkipped` event says so. Encrypted settings still need an `APP_KEY`.

## After migrating

```bash
php artisan migrate
php artisan laranail::settings.promote
```

Promotion copies only what was written to the runtime file; shipped defaults stay in the shipped
file, so a later release can change them. Keys that already have a database row are skipped and
listed (use `--force` to overwrite), and the file is archived as
`settings.promoted-<timestamp>.json`. Running it again does nothing. To replay an archive into a
database restored from an older backup:

```bash
php artisan laranail::settings.promote --from=laranail/settings/settings.promoted-20261109_101500_000000.json
```

Then remove `LARANAIL_SETTINGS_BOOTSTRAP`. From then on an unreachable database raises
`DriverUnavailable`; it never falls back to the file.

---

[← Docs index](../../README.md#documentation)
