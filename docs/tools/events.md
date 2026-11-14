# Events

Thirty events, all `final readonly` classes in `Simtabi\Laranail\Settings\Events`, carrying identifiers and counts, never a value.

## Changes

| Event | Dispatched | Carries |
|---|---|---|
| `SettingSaving` / `SettingSaved` | around a set (and the write a rollback makes) | `store`, `scope`, `key`, `locale`, `version` |
| `SettingForgetting` / `SettingForgotten` | around a forget | the same; `version` is the removed one |
| `SettingLocked` / `SettingUnlocked` | after a lock or unlock | the same, with the new `version` |
| `SettingRolledBack` | after a rollback | the same, plus `toVersion` (the version returned to) and `version` (the new one) |
| `ScopeFlushing` / `ScopeFlushed` | around `flush()` | `store`, `scope` |
| `StorePromoting` / `StorePromoted` | around a promotion | counts, the archive path |
| `CategorySaving` / `CategorySaved` | around a category create, rename or move | `store`, `slug` |
| `CategoryMoved` | after a move, besides `CategorySaved` | `store`, `slug`, `parent` (null: top level) |
| `CategoryRenamed` | after a rename, besides `CategorySaved` | `store`, `slug` |
| `CategoryDeleting` / `CategoryDeleted` | around a delete | `store`, `slug` |

## Approvals

| Event | Dispatched | Carries |
|---|---|---|
| `ChangeRequested` | a change request was opened, by `propose()` or by a write that needs approval | `id`, `store`, `scope`, `key`, `locale`, `operation` |
| `ChangeApproved` | a request received an approval | the same, plus `remaining` (approvals still needed) |
| `ChangeApplied` | an approved request was written through the write pipeline (besides `SettingSaved` and its kin) | the same, plus `version` |
| `ChangeStale` | an approved request was not applied: its setting changed after it was opened | the same |
| `ChangeRejected` | a request was rejected; nothing was written | the same |
| `ChangeCancelled` | the requester cancelled a request; nothing was written | the same |

None carries the proposed value. See [Approvals](approvals.md).

## Which call fires what

| Call (PHP, REST or console) | Events |
|---|---|
| `set()`, `setMany()`, `remember()` (when it writes), `PUT …/settings/{key}` | `SettingSaving`, `SettingSaved`; or `ChangeRequested` |
| `forget()`, `forgetMany()`, `DELETE …/settings/{key}` | `SettingForgetting`, `SettingForgotten`; or `ChangeRequested` |
| `lock()`, `unlock()` | `SettingLocked`, `SettingUnlocked`; or `ChangeRequested` |
| `rollback()` | `SettingSaving`, `SettingSaved`, `SettingRolledBack` |
| `flush()`, deleting a `HasSettings` model | `ScopeFlushing`, `ScopeFlushed` |
| `promote()` | `StorePromoting`, `StorePromoted` |
| `propose()`, `POST …/changes` | `ChangeRequested` |
| `approvals()->approve()` | `ChangeApproved`, then `ChangeApplied` or `ChangeStale` once enough approvals arrive |
| `approvals()->reject()`, `->cancel()` | `ChangeRejected`, `ChangeCancelled` |
| `categories()` writes | `CategorySaving`, `CategorySaved`, `CategoryRenamed`, `CategoryMoved`, `CategoryDeleting`, `CategoryDeleted` |
| `import()` | the events of each write it makes |
| any refused call | `AccessWasDenied` or `VerificationWasRequired` |
| `sync-definitions`, `prune-audit`, `prune-history`, `prune-orphans` | `DefinitionsSynced`, `AuditPruned`, `HistoryPruned`, `OrphansPruned` |
| reads (`get()`, `all()`, `GET …`) | none |

## Refusals

| Event | Dispatched | Carries |
|---|---|---|
| `AccessWasDenied` | an actor is refused an ability, or a call is refused for want of an actor | `ability`, `subject` (store, key, owner, locale, flags), `actor` (null when none), `refusal` (`access_denied`, `approval_unavailable`, `actor_required`), `reason` |
| `VerificationWasRequired` | an allowed actor still needs step-up verification | `ability`, `subject`, `actor` |

A refusal is dispatched at once, not after commit: the refusal throws, so any transaction around the call rolls back, and a listener watching for refusals must still hear of it. The refusal is also audited and logged.

## Maintenance

| Event | Carries |
|---|---|
| `DefinitionsSynced` | `synced`, `removed`, `conflicts`, `skipped` |
| `AuditPruned` | `removed`, `keepDays` |
| `HistoryPruned` | `removed`, `keepDays`, `keepVersions` (null where that rule was off) |
| `OrphansPruned` | `owners`, `rows` |
| `LayerSkipped` | `store`, `layer`, `reason`, when bootstrap mode skips a database layer |

## Delivery

Every event that announces a change implements `ShouldDispatchAfterCommit`: inside a transaction it waits for the commit, and a rolled-back change announces nothing. Only the refusals (above) and `LayerSkipped` are dispatched at once. There are no read events; reads are the hot path.

Because events never carry a value, a queued listener cannot write a secret into the jobs table. A listener that needs the value reads it through the settings API, as the actor it acts for.

## Built-in listeners

| Key | Listener | Default |
|---|---|---|
| `listeners.alerts` | `Listeners\SendSettingsAlert`: [alerts](alerts.md) | on, but alerts need `alerts.enabled` |
| `listeners.log` | `Listeners\LogSettingsEvent`: every event to the [operational log](audit-and-logging.md), refusals at `warning` | off (the audit already records the actions) |

Both are registered by class at boot, so nothing is built until an event fires. The cache needs no listener: writes invalidate it themselves. To add your own, see [Listen to settings events](../recipes/listening-to-settings-events.md).

---

[← Docs index](../../README.md#documentation)
