# Approve a change

Require a second person for sensitive settings, then review and apply their requests.

## Require approval

```php
$registry->define('billing.plan')->requiresApproval()->register();
// or, for a group of keys: 'stores' => ['settings' => ['approvals' => ['keys' => ['billing.*']]]]
```

Writes now open change requests. Grant `laranail-settings.approve` to reviewers:

```php
Gate::define('laranail-settings.approve', fn (User $user) => $user->is_admin);
```

## Propose, review, approve

```php
settings()->as($editor)->because('upgrade for ACME')->set('billing.plan', 'pro');   // a change request

foreach (settings()->as($reviewer)->approvals()->pending()->get() as $request) {
    echo "{$request->id} {$request->operation->value} {$request->key} by {$request->requestedById}\n";
}

settings()->as($reviewer)->approvals()->approve($id, 'agreed with finance');
```

The editor cannot approve their own request (`approvals.allow_self`). If `billing.plan` changed after the request was opened, approving marks it `stale` instead of overwriting; ask for a new request. Schedule `laranail::settings.prune-changes` daily to expire forgotten requests.

## The same flow over HTTP

```http
PUT /api/settings/v1/stores/settings/settings/billing.plan
{"value": "pro", "reason": "upgrade for ACME"}
```

answers `202 Accepted` with the change request (`"status": "pending"`). A reviewer lists and decides:

```http
GET  /api/settings/v1/stores/settings/changes?status=pending
POST /api/settings/v1/stores/settings/changes/01JC…/approve   {"reason": "agreed with finance"}
POST /api/settings/v1/stores/settings/changes/01JC…/reject    {"reason": "not this quarter"}
```

The requester can follow their own requests with `GET …/changes/{id}` and withdraw one with `POST …/changes/{id}/cancel`. To propose without trying to write, `POST …/changes` with `{"key": "billing.plan", "value": "pro"}`. A decided request answers `409 change-request-closed` to a second decision.

## Tell the reviewers

Listen to `ChangeRequested` (identifiers only, never the value) or add an [alert](../tools/alerts.md) rule for it, so reviewers hear of a request without polling.

See [Approvals](../tools/approvals.md).

---

[← Docs index](../../README.md#documentation)
