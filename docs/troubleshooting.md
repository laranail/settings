# Troubleshooting

The errors people meet most often, what each means, and the command that shows the cause.

## Two commands to run first

```bash
php artisan laranail::settings.doctor                 # tables, columns, encryption and stored data
php artisan laranail::settings.show mail.from_address # where one value comes from, and the last change
```

`doctor` exits non-zero on a failure, so it can also gate a deploy. `show` prints the value, the
layer and scope that answered, the version, the flags and the latest audit entry, then the
resolution steps. From PHP, `Settings::explain('mail.from_address')` returns the same steps.

## Errors

| You see | Cause | Fix |
|---|---|---|
| a store set to `promote: auto` still reads from its runtime file | promotion runs only once the connection answers and the table exists, after migrations, a request or a queued job; a failed attempt is logged as a warning and retried on the next one | run `doctor` (`promotion`), read the log entry (`could not promote store`), fix the cause, or run `laranail::settings.promote` |
| `DriverUnavailable`: the database layer is unavailable (unreachable, or table missing) | the migration has not run, or the connection is down; the package never falls back to defaults silently | `php artisan migrate`, then `doctor` (`readiness`). While installing, see [bootstrap mode](recipes/moving-from-files-to-the-database.md) |
| `InvalidConfiguration` naming a path such as `laranail.settings.stores.settings.layers` | that config entry has the wrong shape or names something unknown | fix the entry the message names; [Configuration](configuration.md) lists every key |
| `AccessDenied` for a call made with `as($user)` | the `laranail-settings.<ability>` gate is not defined, or it said no; an undefined ability is denied | define the gate, or call without an actor for trusted code; see [Access control](tools/access-control.md) |
| `VerificationRequired` (REST `428`) | the ability is in `verification.abilities`, or the definition `requiresVerification()` | send the user through `password.confirm`, or use the `actor` verifier with a fresh `verifiedAt` |
| `LockedSetting` (REST `423`) | the setting is locked for that scope, or its definition's precedence forbids store writes | `unlock()` it, or write as an actor allowed `override_lock` when the store's `locks.overridable` is on |
| a write returns but the value does not change | the setting needs approval, so the write opened a change request | see [Approvals](tools/approvals.md); `ApprovalUnavailable` means the store has no active database layer to hold one |
| `OwnerNotAllowed` | no store lists the owner's morph alias in `stores.<name>.owners`, or a dedicated store was asked for a global value | add the alias to the store, or write global values to its fallback store |
| `InvalidIdentifier` for a model owner | the model has no morph alias, so its alias would be a class name | register one: `Relation::enforceMorphMap(['user' => User::class])` |
| `UnknownSetting` | the store is `strict` and the key has no definition | define the key, or turn `strict` off for that store |
| `ConcurrentModification` (REST `412`) | another writer changed the setting after you read it | read it again and retry; a plain `set()` already retries once |
| `UndecryptableValue` after rotating `APP_KEY` | encrypted values were written with the old key | put the old key in `APP_PREVIOUS_KEYS`, run `laranail::settings.reencrypt`, then `doctor` (`encryption`) |
| a value edited in the database by hand does not show | the cached bag still holds the old rows | `php artisan laranail::settings.clear-cache`; see [Warm the cache after a deploy](recipes/warming-the-cache.md) |
| every REST route answers `404` | `http.enabled` is off, a route group is switched off, or another route already owns the path | set `LARANAIL_SETTINGS_HTTP=true`; move the API with `http.prefix`; see [REST API](tools/rest-api.md) |
| REST `401` | no user is authenticated on the request; the API never runs as trusted code | send a session cookie or a bearer token the configured middleware accepts |

Every exception and its REST answer is listed in [Exceptions](tools/exceptions.md).

---

[← Docs index](../README.md#documentation)
