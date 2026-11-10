# Import and export

A store as one portable JSON document: categories by slug, settings by scope, key and locale, never by a numeric id (G-42).

## The document

```json
{
    "format": "laranail/settings",
    "version": 1,
    "store": "settings",
    "mode": "redacted",
    "exported_at": "2026-11-10T09:00:00+00:00",
    "categories": [{"slug": "mail", "title": "Mail"}, {"slug": "smtp", "title": "SMTP", "parent": "mail"}],
    "settings": [
        {"scope": "global", "key": "mail.port", "locale": "", "value": 587},
        {"scope": "global", "key": "mail.password", "locale": "", "redacted": true, "encrypted": true},
        {"scope": "user:7", "key": "theme", "locale": "", "value": "dark", "locked": true}
    ]
}
```

A value is the stored JSON exactly as it is, so nothing is lost whatever its type. Only the current tenant's rows are exported.

## Secrets (G-10)

| Mode | Encrypted values | How |
|---|---|---|
| `redacted` (default) | left out | `export()` / `laranail::settings.export` |
| `ciphertext` | the stored ciphertext: importable only under the same `APP_KEY`, or one in `APP_PREVIOUS_KEYS` | `ExportMode::Ciphertext` / `--with-secrets` |
| `plaintext` | decrypted; needs `view_sensitive` too | `ExportMode::Plaintext` / `--decrypt`, which asks you to type the store name (or `--confirm=<store>`) |

Nothing is ever logged, and the audit entry records the mode and the counts only.

## Exporting, comparing, importing

```php
$document = $settings->as($admin)->export();                    // needs export
$diff = $settings->as($admin)->compare($document);              // added, removed, changed, unchanged, unknown
$preview = $settings->as($admin)->previewImport($document);      // what an import would do
$report = $settings->as($admin)->import($document);             // needs import
```

```bash
php artisan laranail::settings.export storage/settings.json --with-secrets
php artisan laranail::settings.diff storage/settings.json          # exits 1 when they differ
php artisan laranail::settings.import storage/settings.json         # a dry run
php artisan laranail::settings.import storage/settings.json --apply
php artisan laranail::settings.import storage/settings.json --apply --trusted
```

An import creates missing categories first, by slug, parents before children, then writes each setting through the normal write pipeline as the actor: validated and cast under the destination's definition, encrypted, with history and one audit entry per key. A setting that needs approval opens a change request instead (the report lists them). Everything runs in one transaction, so one failed write leaves the store as it was. Redacted values, owners the store does not accept and global values for a dedicated store are skipped, each with its reason in the report.

Locks and system categories in a document are applied only by a trusted import (`--trusted` on the console); the PHP `import()` and the REST API never apply them. `import` needs step-up verification by default (`verification.abilities`).

Other packages' settings are read by [importers](importers.md).

---

[← Docs index](../../README.md#documentation)
