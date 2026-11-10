# History

Every change to a setting on a database layer appends one history entry: the value before and after, as stored, in the same transaction as the change.

## What is recorded

`set`, `forget`, `lock`, `unlock` and `rollback` each append an entry to `settings_history` (one table per connection, like the audit table). An entry holds:

| Field | Meaning |
|---|---|
| `store`, `scope`, `key`, `locale` | which setting; the history is always looked up by these four |
| `setting_ref` | the row's `uid` (or its id where the identity mode has no uid), as a string |
| `operation` | `set`, `forget`, `lock`, `unlock` or `rollback` |
| `old_value`, `new_value` | the payloads exactly as the values table stored them: encoded JSON, and still encrypted for an encrypted setting |
| `old_type`, `new_type`, `old_encrypted`, `new_encrypted` | what is needed to read the payloads back |
| `version` | the row's version after the change; for `forget`, the version that was removed |
| `causer_type`, `causer_alias`, `causer_id`, `reason` | the actor (`system` when there was none) and the reason given with `because()` |
| `created_at` | from `now()` |

A failed history insert rolls the change back with it, so the history never misses a change.

A `forget` writes a tombstone (no new value). Setting the key again continues its version from the history, so "version 3" always means one value: `set:1`, `forget:1`, `set:2`. `flush()` and promotion are audited but write no history entries. History is not written for file layers, or when the schema was recorded with `history.enabled` off.

## Reading the history

```php
$entries = $settings->as($admin)->history()
    ->key('mail.from_address')
    ->global()                       // or ->for($user); a handle's own owner is applied already
    ->since(now()->subMonth())
    ->versions(3, 8)                 // inclusive; either end may be null
    ->get();                         // list<HistoryEntry>, newest version first

$page = $settings->history()->key('mail.from_address')->paginate(50);
$next = $settings->history()->key('mail.from_address')->paginate(50, $page->nextCursor);
```

`history()` starts from the handle's store, owner and locale. It needs `view_history` when the handle carries an actor. For an actor without `view_sensitive`, entries of encrypted or sensitive settings come back with their payloads removed (`HistoryEntry::redacted()`); `HistoryEntry::toArray()` never includes an encrypted payload.

## Comparing two versions

```php
$diff = $settings->as($admin)->historyDiff('mail.password', 3, 5);

$diff->from;      // the value at version 3, or null
$diff->to;        // the value at version 5, or null
$diff->changed;   // whether the stored payloads differ
$diff->redacted;  // true: the actor may not view this value, so from and to are null
```

`historyDiff()` needs `view_history`. It decrypts an encrypted or sensitive value only for an actor holding `view_sensitive`, or for a trusted call without an actor (which reads every value, as `get()` does). Nothing is decrypted before that decision. A version that is not in the history raises `HistoryUnavailable`.

## Rolling back

```php
$version = $settings->as($admin)->because('INC-42')->rollback('mail.from_address', 3);
```

The value of version 3 is read back from the history, decrypted and decoded, then written as a new version through the normal write pipeline: validation, casting, encryption, lock rules, the version check against the current row, history and audit. It needs `rollback`, which `verification.abilities` lists by default, so step-up verification applies. It returns the new version and dispatches `SettingRolledBack`. See [Roll back a setting](../recipes/rolling-back-a-setting.md).

## Retention

`history.keep_days` drops entries older than that many days; `history.keep_versions` keeps only that many versions of each setting. Either may be null; `laranail::settings.prune-history` applies them (needs `prune`, audited, dispatches `HistoryPruned`). Retention covers every tenant.

## Switching it off

`history.enabled` is a [schema switch](schema-switches.md): it decides whether the migration creates the table, and the package reads the recorded value, not live configuration. With it off, `history()`, `rollback()` and `historyDiff()` raise `HistoryUnavailable`, and a key set again after a forget starts at version 1.

`reencrypt` (rewriting payloads after a key rotation, history included) is not part of this release.

---

[← Docs index](../../README.md#documentation)
