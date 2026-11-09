# Configuration

Every key in `config/laranail/settings.php`, read at `config('laranail.settings.*')`.

## Keys

| Key | Default | Purpose |
|---|---|---|
| `default` | `settings` (`LARANAIL_SETTINGS_STORE`) | the store used when a call names neither a store nor an owner |
| `stores.<name>.layers` | `['runtime', 'shipped']` | layer names, in read order; writes go to the first writable one |
| `stores.<name>.owners` | `[]` | morph aliases this store holds values for; each alias belongs to one store |
| `stores.<name>.strict` | `false` | refuse keys without a definition |
| `stores.<name>.inherits` | `false` | let owners fall back to parents and the global value for keys whose definition does not say otherwise |
| `layers.<name>.driver` | — | `json`, `php`, `config`, `array`, or a custom driver |
| `layers.<name>.writable` | `true` | set `false` for a read-only layer |
| `layers.<name>.disk` | `local` (`LARANAIL_SETTINGS_RUNTIME_DISK`) | JSON driver: a private filesystem disk |
| `layers.<name>.path` | — | JSON: a path on the disk containing `{store}`; PHP: a path from the application root |
| `layers.<name>.prefix` | — | config driver: the config prefix to read |
| `definitions` | `[]` | `key => attributes`; see [Definitions](tools/definitions.md) |

Store names, keys, locales and owner aliases are validated, and a misconfiguration raises `InvalidConfiguration` naming the config path on first use.

## Publishing

```bash
php artisan laranail::settings.install
```

The published file at `config/laranail/settings.php` is merged over the package's defaults.

---

[← Docs index](../README.md#documentation)
