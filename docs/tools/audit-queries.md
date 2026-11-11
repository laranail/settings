# Audit queries

`$settings->audit()` reads the audit trail from PHP: an immutable query narrowed by owner, store, key, action, outcome and time, gated by `view_audit`.

## Querying

```php
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;

$entries = $settings->as($admin)->audit()
    ->for($user)                         // a Settable or OwnerRef: that owner's scope
    ->key('mail.from_address')
    ->action(AuditAction::Update)
    ->outcome(AuditOutcome::Allowed)
    ->since(now()->subWeek())
    ->until(now())
    ->get();                             // list<AuditEntry>, newest first
```

`->store('billing')` narrows to one store and reads the audit table on that store's connection
(the default store's otherwise); a handle built with `store()` starts narrowed to it. `->global()`
keeps entries about global values only. `get(100)` limits the result.

## Large trails

```php
foreach ($settings->audit()->since($from)->cursor() as $entry) { … }   // LazyCollection, streamed

$page = $settings->audit()->paginate(50);                              // AuditPage
$next = $settings->audit()->paginate(50, $page->nextCursor);
```

An `AuditPage` holds `entries`, `perPage`, `nextCursor` and `previousCursor` (opaque strings, null
at either end) and `hasMore()`.

## Access

With an actor on the handle, every run needs `view_audit` (checked against the filtered store,
key and owner); a denial is itself audited. Without `store()`, the query returns entries of the
stores the actor may audit only, and is refused when there is none. Without an actor the call is trusted, unless
`authorization.require_actor` is on. Entries never carry a value, encrypted or not, so there is
nothing to redact.

A store without an active database layer audits to the log, which cannot be queried:
`audit()` raises `DriverUnavailable` for it. The action behind the query is
`Actions\QueryAudit`, which the REST API will share.

---

[← Docs index](../../README.md#documentation)
