# Caching

One cache sits in front of every database layer and holds each scope's raw rows, invalidated on every write.

## What is cached

Database layers only: `Drivers\CachingDriver` wraps each store's database layer when
`cache.enabled` is on (the default). File layers (`json`, `php`, `config`) are read directly; they
are already local and cheap.

The unit is a **bag**: every row one scope (`global`, `user:42`, `team:7`) has in one store's
table, every locale included. Bags are raw records, never resolved values, so a write to
`team:7` invalidates exactly one bag and every user who inherits from team 7 sees the change on
their next read. A scope with no rows is cached as an empty bag, so a miss is cached too.

A read through the cascade (owner, parents, global) costs, per distinct store in the chain:

| State | Database queries | Cache calls |
|---|---|---|
| cold (bags not cached) | one, covering every scope the read needs from that store | two (tokens, then bags) and one write |
| warm (bags cached) | none | two |
| again in the same request | none | none (bags and tokens are held in memory for the request) |

## Keys

A bag's key is `<prefix>:<sha1>:<sha1 of its generation>`. The first hash covers connection
name, database name, tenant, store, table and scope as a length-prefixed tuple, so
`a` + `b:c` and `a:b` + `c` never meet, and neither do two tenants or two databases that both
have a `global` scope (G-37). The tenant comes from the configured `TenantDiscriminator` in every
tenancy mode, `none` included.

## Invalidation

A bag's generation is three tokens: its scope's, its store's and a global epoch. Each token is a
random ULID, and invalidating replaces one:

| Trigger | Token replaced |
|---|---|
| `set()`, `forget()`, `lock()`, `unlock()` | the written scope's (current tenant) |
| `flush()` and an owner model being deleted | the flushed scope's |
| `promote()` | the store's (every tenant) |
| `->for($owner)->clearCache()` | that owner's scope |
| `clearCache()`, `clear-cache --store=` | the store's (every tenant) |
| `laranail::settings.clear-cache` | the epoch (everything) |

Nothing is deleted from the cache store, and the application's own cache entries are never
touched; entries under a replaced token simply stop being read and expire. Replacing a token
rather than deleting the bag is what makes stale-while-revalidate safe: a refresh computed before
a write lands under the old generation, where no reader looks any more. A random token also
survives eviction: an evicted token is replaced by a new random one and the next read misses,
where a counter would restart at zero and could revive an old entry.

Writes inside a transaction invalidate when it commits, and not at all when it rolls back. While a
transaction is open on a layer's connection, that layer reads the database directly and caches
nothing, so a write's own transaction sees its uncommitted rows and nothing uncommitted reaches
the cache.

> A row changed past the API (a raw query, a migration, an Eloquent `Models\Setting` save) does not
> invalidate anything. Run `laranail::settings.clear-cache`, or `clearCache()` on a handle, after
> such a change.

## Stale-while-revalidate

A bag is fresh for `cache.ttl` seconds. For `cache.stale` seconds after that, a read is still
answered from it while Laravel's `Cache::flexible()` refreshes it after the response. Set
`cache.stale` to `0` to turn that off; the bag then expires at `ttl`.

## The cache store

`cache.store` names a store from `config/cache.php`; null is the application's default store. Use
a store every server shares (Redis, Memcached, the database store) on more than one server: with
`array` or `file`, a write on one server leaves the other servers' bags stale until they expire.
`cache.prefix` begins every key, so two applications can share one store.

A failing cache store never fails a read: the bag is read from the database and the failure is
written to the operational log (`logging.channel`) as a warning. A failed invalidation is logged
the same way, and leaves bags stale until they expire or the cache is cleared.

## Octane and queues

The cache service is bound `scoped`, so the bags and tokens it holds in memory last one request
or one queued job. Another process's write is seen on the next request or job; within one long
request, values read earlier are not re-read.

## From PHP

```php
use Simtabi\Laranail\Settings\Facades\Settings;

Settings::clearCache();                          // the default store, every tenant
Settings::store('user_settings')->clearCache();  // another store
Settings::for($team)->clearCache();              // one owner's cached values
Settings::warmCache();                           // load the global scope now; returns the record count
Settings::for($user)->warmCache();
```

Both need `manage_stores` when the handle carries an actor (and, with the default
`verification.abilities`, step-up verification), and both are audited as a `manage_stores` entry
whose `meta.operation` is `cache.clear` or `cache.warm`. They do nothing when caching is off.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `cache.enabled` | `true` (`LARANAIL_SETTINGS_CACHE`) | cache database layers |
| `cache.store` | `null` (`LARANAIL_SETTINGS_CACHE_STORE`) | a store from `config/cache.php`; null is the default store |
| `cache.prefix` | `laranail-settings` | the start of every key |
| `cache.ttl` | `3600` | seconds a bag is fresh (at least 1) |
| `cache.stale` | `300` | seconds after `ttl` a stale bag is answered while it is refreshed; `0` for none |

---

[← Docs index](../../README.md#documentation)
