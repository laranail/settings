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
| `log` (default) | the operational log, at `info` |
| `null` | nowhere |
| a class name | any class implementing `AuditSink` |

A `database` sink, with retention and a query API, arrives with the database layer. Until then an allowed write is audited after it is stored; there is no transaction to roll back.

## The operational log

Refusals are logged as warnings, and runtime configuration changes and log-sink audit entries at `info`, with store, key, scope and actor identifiers.

| Key | Default | Effect |
|---|---|---|
| `logging.channel` | `null` | `null` is the application's default channel, a name picks a channel, `false` switches the log off |
| `logging.level` | `info` | messages below it are dropped; an unknown level raises `InvalidConfiguration` |

The log goes through an injected PSR-3 logger built from Laravel's `LogManager`, so any channel driver works. Hosts that want their own reaction listen to the package's events as well.

---

[← Docs index](../../README.md#documentation)
