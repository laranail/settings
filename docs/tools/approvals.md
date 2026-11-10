# Approvals

Four-eyes changes: a write to a setting that needs approval becomes a change request, and is applied only after someone else approves it.

## Which writes need approval

A write needs approval when the setting's definition says so, or when its key matches one of the store's `approvals.keys` globs:

```php
$registry->define('billing.plan')->requiresApproval()->register();
```

```php
// config/laranail/settings.php
'stores' => [
    'settings' => [
        'layers'    => ['database', 'runtime', 'shipped'],
        'approvals' => ['keys' => ['billing.*', 'mail.password']],
    ],
],
```

Such a write (`set`, `forget`, `lock`, `unlock`) is not made. It opens a change request instead, and `set()` still returns nothing. `propose()` opens one for any setting and returns it:

```php
$settings->as($editor)->because('INC-7')->set('billing.plan', 'pro');          // a change request
$request = $settings->as($editor)->propose('site.name', 'Acme Ltd');           // ChangeRequest
```

Proposing needs the ability of the write it proposes (`update` for a value). The value is validated, cast, encrypted for an encrypted definition, and encoded at once, so an invalid value is refused now and a secret is encrypted at rest in `settings_change_requests`. The request records the setting's current version as its base version.

## Deciding

```php
$approvals = $settings->as($reviewer)->approvals();   // the handle's store

$approvals->pending()->get();                          // newest first
$approvals->key('billing.plan')->for($team)->get();
$approvals->find($id);

$approvals->approve($id, 'checked the invoice');
$approvals->reject($id, 'not this quarter');
$settings->as($editor)->approvals()->cancel($id);      // the requester only
```

| Rule | Default | Key |
|---|---|---|
| Approvals needed to apply | 1 | `approvals.min_approvals` |
| The requester may approve their own request | no | `approvals.allow_self` |
| Days before an open request expires | 7 | `approvals.expire_after` (null: never) |
| Days a decided request is kept | 90 | `approvals.keep_days` (null: for ever) |

Approving and rejecting need `approve` on the setting. Listing needs `approve` to see everyone's requests; an actor without it sees only their own. Trusted code without an actor sees and decides everything.

## Applying

The last approval applies the change through the normal write pipeline, with the base version as the expected version. A setting that changed after the request was opened is never overwritten: the request becomes `stale` and is returned rather than thrown. An expired request is marked `expired` and refused with `ChangeRequestClosed`. Applied, rejected, cancelled, stale and expired requests cannot be decided again.

| Status | Meaning |
|---|---|
| `pending` | waiting for its first approval |
| `approved` | has approvals, needs more |
| `applied` | written |
| `rejected`, `cancelled` | nothing written |
| `stale` | the setting changed first; nothing written |
| `expired` | not decided in time; nothing written |

Events, all dispatched after commit and carrying identifiers only: `ChangeRequested`, `ChangeApproved` (with how many approvals remain), `ChangeApplied` (with the new version), `ChangeRejected`, `ChangeCancelled`, `ChangeStale`. The audit trail records the proposal (`propose`, outcome `pending`), each approval, the rejection or cancellation, and a self-approval refused, each with the change request's id.

## Where requests are kept

The `settings_change_requests` table, one per connection a store uses (like the history). A store without an active database layer cannot hold requests: a write that needs approval there raises `ApprovalUnavailable`.

Run `laranail::settings.prune-changes` on a schedule: it marks open requests past their expiry as `expired` and deletes decided ones older than `approvals.keep_days`. `doctor` warns about open requests past their expiry.

---

[← Docs index](../../README.md#documentation)
