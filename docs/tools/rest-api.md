# REST API

A JSON API over every capability of the PHP API (§16.10), in this package and off by default.

## Turning it on

```php
// config/laranail/settings.php
'http' => [
    'enabled'    => true,
    'prefix'     => 'api/settings',      // routes live under api/settings/v1
    'middleware' => null,                // null: ['api', 'auth:sanctum'] with Sanctum, else ['api', 'auth']
    'rate_limit' => 60,                  // per minute, per user (or IP); 0 for none
    'routes'     => ['approvals' => false],   // switch groups off
],
```

No route is registered until `enabled` is true. Controllers are built afresh for every request, so under Octane no request's actor, tenant or stores reach the next one. Routes are named `laranail-settings.api.<capability>` (the owner form of a settings route adds `owners.`), run through `http.middleware` and the `laranail-settings` rate limiter, and every write runs through the idempotency middleware. The host's middleware authenticates; the configured actor resolver turns the request into an [actor](access-control.md) on the `rest` channel, so with the `sanctum` resolver a token's `settings:<ability>` abilities are a ceiling on what it may do. A request nobody is authenticated for is refused (`401`); the API never runs as trusted code.

## Endpoints

All under `/{prefix}/v1`. `{owner}` is either nothing (the global value) or `owners/{alias}/{id}/`, an owner by morph alias and id.

| Method and path | Capability | Ability |
|---|---|---|
| `GET stores` | `stores.index` | `view` (stores the actor may not view are left out) |
| `GET stores/{store}/definitions` | `definitions.index` | `view` |
| `GET stores/{store}/{owner}settings` | `settings.index` | `view`; keys the actor may not view are left out |
| `GET stores/{store}/{owner}settings/{key}` | `settings.show` | `view` (`view_sensitive` for the value) |
| `PUT stores/{store}/{owner}settings/{key}` | `settings.update` | `update` |
| `DELETE stores/{store}/{owner}settings/{key}` | `settings.destroy` | `forget` |
| `POST`, `DELETE stores/{store}/{owner}settings/{key}/lock` | `settings.lock`, `settings.unlock` | `lock`, `unlock` |
| `GET stores/{store}/{owner}settings/{key}/explain` | `settings.explain` | `view` |
| `GET stores/{store}/{owner}settings/{key}/history` | `settings.history` | `view_history` |
| `POST stores/{store}/{owner}settings/{key}/rollback` | `settings.rollback` | `rollback` |
| `GET audit` | `audit.index` | `view_audit` |
| `GET`, `POST stores/{store}/changes` | `changes.index`, `changes.store` | `approve` to see all; the write's ability to propose |
| `GET stores/{store}/changes/{id}` | `changes.show` | as listing |
| `POST stores/{store}/changes/{id}/approve`, `/reject`, `/cancel` | `changes.approve`, … | `approve`; cancel: the requester |
| `GET`, `POST stores/{store}/categories`, `PATCH`, `DELETE …/{slug}` | `categories.*` | `view`, `manage_categories` |
| `GET configuration`, `PUT`, `DELETE configuration/{stores\|layers}/{name}` | `configuration.*` | `manage_stores` |
| `PUT`, `DELETE stores/{store}/definitions/{key}` | `definitions.update`, `definitions.destroy` | `manage_definitions` |
| `POST stores/{store}/export`, `/import`, `/diff` | `transfer.*` | `export` (`view_sensitive` for plaintext), `import` |

Each capability runs the same action the PHP API runs (`Enums\Capability::actions()`), and an architecture test fails if a route, the capability list or a controller drifts from the others.

## Requests and responses

A setting:

```http
PUT /api/settings/v1/stores/settings/settings/site.name
Content-Type: application/json
If-Match: "4f1c…"
Idempotency-Key: 6c0d1e8e-…

{"value": "Acme Ltd", "reason": "INC-7"}
```

```json
{"data": {"store": "settings", "scope": "global", "key": "site.name", "locale": "", "found": true,
          "layer": "database", "from": "global", "version": 2, "redacted": false, "value": "Acme Ltd"}}
```

- **ETags.** Every setting response carries an `ETag` derived from store, scope, key, locale and version. Send it back as `If-Match` and the write is checked against that version; a mismatch answers `412`.
- **Approvals.** A write to a setting that needs approval answers `202 Accepted` with the change request.
- **Lists** are `{"data": [...], "meta": {"next_cursor": …}}`; pass `?cursor=` for the next page and `?limit=` (1-200).
- **Locales**: `?locale=fr`.
- **Values the actor may not view** are left out, not masked: a list skips the key, a single setting answers `"redacted": true` without `value`.
- **Idempotency.** A write with an `Idempotency-Key` header is answered from cache when retried (same user, method, path and key) for `http.idempotency.ttl` seconds (24 hours), with `Idempotent-Replayed: true`.

## Errors

RFC 9457 problem details, `application/problem+json`:

```json
{"type": "https://opensource.simtabi.com/documentation/laranail/settings/problems/locked", "title": "Locked", "status": 423,
 "detail": "Setting [theme] in store [settings] is locked for scope [user:1].", "instance": "/api/settings/v1/…"}
```

| Status | Raised by |
|---|---|
| 401 | no authenticated actor (`actor-required`, `unauthenticated`) |
| 403 | `AccessDenied` |
| 404 | `UnknownStore`, `UnknownSetting`, `UnknownOwner`, `UnknownCategory`, `UnknownChangeRequest` |
| 409 | `ApprovalUnavailable`, `ChangeRequestClosed`, `OwnerNotAllowed`, `StoreNotWritable`, `HistoryUnavailable` |
| 412 | `ConcurrentModification` (`If-Match`) |
| 422 | invalid values, identifiers, imports, definitions or configuration, and request validation (with `errors`) |
| 423 | `LockedSetting` |
| 428 | `VerificationRequired` |
| 429 | the rate limit |
| 503 | `DriverUnavailable` |

## Owners

`owners/{alias}/{id}` resolves through the `OwnerResolver` contract. The default takes the alias from `http.owners` (alias => class) or the morph map; an alias in neither is `404`, never a class name from the URL. A `Settable` model is loaded, so its parents take part in reads; a missing record is `404`. Bind your own `OwnerResolver` to change that.

## OpenAPI

```bash
php artisan laranail::settings.openapi --output=public/settings-openapi.json
```

writes an OpenAPI 3.1 document of the routes as registered, with a schema per resource. The package's tests validate real responses against it. See [Call the REST API with Sanctum](../recipes/calling-the-rest-api-with-sanctum.md).

---

[← Docs index](../../README.md#documentation)
