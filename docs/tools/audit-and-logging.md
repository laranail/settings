# Audit and logging

Two outputs: an audit trail of actions and denials, and an operational log; neither ever holds a value.

## What is audited

Every `set`, `forget`, `lock` and `unlock`, allowed or failed, and every refusal: a denial, a verification failure, a missing actor and a write that needs approval. Runtime configuration changes are audited too. Reads are not.

## Audit entries

`Data\AuditEntry` has no field for a value, encrypted or not.

| Field | Meaning |
|---|---|
| `id`, `occurredAt` | a ULID and the time, from `Str::ulid()` and `now()` |
| `action` | `AuditAction`: each ability, plus `configure` |
| `outcome` | `AuditOutcome`: `allowed`, `denied`, `pending`, `failed` |
| `store`, `scope`, `key`, `locale` | what was addressed |
| `versionFrom`, `versionTo` | the row's version before and after |
| `actorType`, `actorAlias`, `actorId`, `channel` | who, through which surface; `system` without an actor |
| `ip`, `userAgent`, `requestId`, `reason` | the actor's context and the reason given with `because()` |
| `meta` | identifiers only: `refusal`, `detail`, `error`, `override_lock`, `change` |

```php
Settings::as($user)->because('INC-123')->set('mail.from.address', 'ap@acme.test');
```

## Sinks

`audit.sinks` lists `Contracts\AuditSink` implementations; every one receives every entry.

| Sink | Writes to |
|---|---|
| `auto` (default) | `database` for an entry whose store has an active database layer, `log` otherwise |
| `database` | the `settings_audit` table on the store's connection |
| `log` | the operational log, at `info` |
| `null` | nowhere |
| a class name | any class implementing `AuditSink` |

## The audit table and transactions

An allowed change and its audit row are written in one transaction on the store's connection,
so if the audit insert fails, the change is rolled back and the caller gets the exception. A
failure is audited after the rollback; a denial is audited before anything is attempted, so
both survive the refusal. The table has no value column.

| Column | Holds |
|---|---|
| `id` | the entry's ULID |
| `occurred_at` | when, from `now()` |
| `action`, `outcome` | the `AuditAction` and `AuditOutcome` |
| `store`, `scope`, `key`, `locale` | what the action was about |
| `version_from`, `version_to` | the row's versions around the change |
| `actor_type`, `actor_alias`, `actor_id`, `channel` | who, and through which channel |
| `ip`, `user_agent`, `request_id`, `reason` | the actor's context |
| `meta` | identifiers only (the refusal, the category operation) |

Every connection a store uses has its own audit table; the package migration creates it (and
`make-store --connection` for a store added later). In column-mode tenancy each row carries the
tenant, and `Settings::audit()` reads the current tenant's rows only; `prune-audit` applies the
retention to every tenant.

## Querying the trail

`$settings->audit()->for($user)->since(now()->subWeek())->get()` reads entries back, gated by
`view_audit`; see [Audit queries](audit-queries.md).

## Retention

`laranail::settings.prune-audit` deletes rows older than `audit.keep_days` (365 by default), or
`--days`, and audits the prune itself; its action needs `prune` when called with an actor.
Schedule it:

```php
Schedule::command('laranail::settings.prune-audit')->weekly();
```

## The operational log

Refusals are logged as warnings, and runtime configuration changes and log-sink audit entries at `info`, with store, key, scope and actor identifiers.

| Key | Default | Effect |
|---|---|---|
| `logging.channel` | `null` | `null` is the application's default channel, a name picks a channel, `false` switches the log off |
| `logging.level` | `info` | messages below it are dropped; an unknown level raises `InvalidConfiguration` |

The log goes through an injected PSR-3 logger built from Laravel's `LogManager`, so any channel driver works. Hosts that want their own reaction listen to the package's events as well.

---

[← Docs index](../../README.md#documentation)
