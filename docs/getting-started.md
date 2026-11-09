# Getting started

What `laranail/settings` does in this release, and what the next phases add.

## Today

The package registers itself, merges its configuration at `laranail.settings`, and ships the
`laranail::settings.install` command. It reads no settings yet.

```php
config('laranail.settings.default'); // the default store name, 'settings'
```

## Coming next

| Phase | Adds |
|---|---|
| Engine | the `Settings` API, definitions, the value pipeline, file and in-memory drivers, cascade resolution |
| Database | the `settings` and `settings_categories` tables, owners through `HasSettings`, bootstrap before the database, promotion |
| Performance | caching with stale-while-revalidate |
| Governance | history and rollback, export and import, `doctor` |
| Typed classes | strongly typed settings classes over the same engine |

Each phase updates this page as it lands.

---

[← Docs index](../README.md#documentation)
