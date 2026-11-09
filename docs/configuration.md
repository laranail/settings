# Configuration

Every key in `config/laranail/settings.php`, read at `config('laranail.settings.*')`.

## Keys

| Key | Default | Env | Purpose |
|---|---|---|---|
| `default` | `settings` | `LARANAIL_SETTINGS_STORE` | The store used when a call does not name one. |

The file only carries keys the package reads, so a published copy never advertises an option
that does nothing. Stores, layers, identity, ids and cache keys are added as the phases that read
them land.

## Publishing

```bash
php artisan laranail::settings.install
```

The published file at `config/laranail/settings.php` is merged over the package's defaults.

---

[← Docs index](../README.md#documentation)
