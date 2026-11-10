# Re-encryption and rescoping

Two maintenance commands that rewrite stored rows: re-encrypting secrets after a key rotation (§6), and renaming an owner alias (G-30).

## After rotating `APP_KEY`

Encrypted values stay readable after a rotation as long as the old key is in `APP_PREVIOUS_KEYS`. To retire the old key, encrypt everything again under the new one:

```bash
# .env: APP_KEY=<new>, APP_PREVIOUS_KEYS=<old>
php artisan laranail::settings.reencrypt
# then drop the old key from APP_PREVIOUS_KEYS
```

It rewrites every encrypted payload of every database store: live values (every tenant's, trashed rows too), the history's old and new values, and pending change requests. A payload no key can read is counted, reported and left exactly as it was, and the command exits 1. Cached values of every store it touched are forgotten.

From PHP, `ReencryptSettings::handle($actor)` returns the counts. It needs `manage_stores`, which `verification.abilities` lists by default, and is audited (`reencrypt`).

`doctor` reports encrypted values no current or previous key can read.

## After renaming a morph alias

The `scope` of a stored value embeds its owner's morph alias (`user:42`). After renaming an alias in the morph map, rename it in stored settings:

```bash
php artisan laranail::settings.rescope user member
php artisan laranail::settings.rescope 'App\Models\User' user     # rows from before the alias allow-list (G-6)
```

Every scope `<from>:<id>` becomes `<to>:<id>` in every database store, with `settable_type` and the per-row slug of `slug_per_row`, in the history, and in change requests, in chunks of 500. The audit trail is left as it was: it records what happened under the old name. Then update the stores' `owners` lists to the new alias. A row whose new identity already exists makes the database refuse the rename, inside a transaction, so nothing changes.

`doctor` reports scopes whose alias the store does not list.

---

[← Docs index](../../README.md#documentation)
