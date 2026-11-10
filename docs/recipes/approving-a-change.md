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

Over HTTP the same steps are `PUT …/settings/billing.plan` (answers `202`), `GET stores/settings/changes?status=pending` and `POST stores/settings/changes/{id}/approve`.

See [Approvals](../tools/approvals.md).

---

[← Docs index](../../README.md#documentation)
