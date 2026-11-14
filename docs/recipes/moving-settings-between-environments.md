# Move settings between environments

Copy a store from staging to production, or keep environments in step, with export, a dry-run import and a diff.

## Export from the source

```bash
php artisan laranail::settings.export storage/settings-staging.json --store=settings
```

Encrypted values are left out by default, so the file is safe to commit or attach to a ticket. `--with-secrets` keeps their ciphertext (useful only when both environments share `APP_KEY`), and `--decrypt` writes plaintext after you type the store name.

## Compare before importing

```bash
php artisan laranail::settings.diff storage/settings-staging.json --store=settings
```

The diff lists keys `added`, `removed`, `changed` and `unchanged`, never values, and exits 1 when the two differ, so it can gate a deploy.

## Import, dry run first

```bash
php artisan laranail::settings.import storage/settings-staging.json --store=settings            # what would change
php artisan laranail::settings.import storage/settings-staging.json --store=settings --apply    # write it
```

Each setting goes through the write pipeline: validation, casting, encryption, approvals, history and audit all apply, and the whole import is one transaction. Locks and system categories in the document are ignored unless you pass `--trusted`.

## From code or over HTTP

```php
$document = $source->as($admin)->export();
$report = $target->as($admin)->because('sync from staging')->previewImport($document);
$target->as($admin)->because('sync from staging')->import($document);
```

```http
POST /api/settings/v1/stores/settings/export   {"mode": "redacted"}
POST /api/settings/v1/stores/settings/diff     {"document": {…}}
POST /api/settings/v1/stores/settings/import   {"document": {…}, "apply": true}
```

## Secrets

Set secrets in each environment directly (`set()` on an encrypted definition) rather than carrying them in a file. After an `APP_KEY` rotation, run `laranail::settings.reencrypt`.

See [Import and export](../tools/import-export.md).

---

[← Docs index](../../README.md#documentation)
