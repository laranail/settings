# Audit a change

Answer "who changed this setting, when, why, and what was it before" from the audit trail and the value history.

## Who and when: the audit trail

```php
$entries = $settings->as($auditor)->audit()
    ->key('mail.from.address')
    ->since(now()->subMonth())
    ->get(20);

foreach ($entries as $entry) {
    echo "{$entry->occurredAt} {$entry->action->value} {$entry->outcome->value} by {$entry->actorType->value}:{$entry->actorId} via {$entry->channel->value}\n";
}
```

The audit trail records every action, allowed or denied, with the actor, the channel (`php`, `rest`, `console`) and the versions it moved between, but never a value. Denied attempts are there too: filter with `->outcome(AuditOutcome::Denied)`. Needs `view_audit`.

## What it was: the history

```php
$versions = $settings->as($auditor)->history()->key('mail.from.address')->get();
$diff = $settings->as($auditor)->historyDiff('mail.from.address', 3, 4);
```

History keeps the value before and after each change, with the reason given through `because()`. An encrypted or sensitive value is shown only to an actor holding `view_sensitive`; others see that it changed, not to what.

## From the console

```bash
php artisan laranail::settings.history mail.from.address --diff=3..4
```

## Over HTTP

```http
GET /api/settings/v1/audit?key=mail.from.address&since=2026-10-01
GET /api/settings/v1/stores/settings/settings/mail.from.address/history
```

## Make every change carry a reason

```php
$settings->as($request->user())->because($request->input('ticket'))->set('mail.from.address', $address);
```

Over HTTP send `"reason"` in the body. A reason costs nothing and is the first thing an auditor asks for.

## Keep it as long as you need

`audit.keep_days` and `history.keep_days` (365 by default) decide what `prune-audit` and `prune-history` remove; schedule both. Ship entries elsewhere with an extra audit sink (`audit.sinks`) when the trail must outlive the database. See [Audit and logging](../tools/audit-and-logging.md).

---

[← Docs index](../../README.md#documentation)
