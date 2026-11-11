# REST API reference

Every route of the JSON API, 39 in all (30 route names, nine of them with an owner form), with its ability, request, response, status codes and problems. [REST API](rest-api.md) explains turning it on, authentication, ETags and idempotency; this page is the per-route reference.

Paths are under `/{http.prefix}/v1`, by default `/api/settings/v1`; set `http.prefix` (`LARANAIL_SETTINGS_HTTP_PREFIX`) to move them, for example when the application already has routes under `/api/settings` (Laravel silently keeps the last route registered for a method and URI), or to an empty string to mount them at `/v1`. Route names are `laranail-settings.api.<name>`, and `laranail-settings.api.owners.<name>` for an owner form; they do not change with the prefix. A test reading the live router fails when a registered route is missing from this page.

## Conventions

| Topic | Rule |
|---|---|
| Authentication | the host's `http.middleware` authenticates; the actor resolver turns the request into an actor on the `rest` channel. No actor: `401`. The API never runs as trusted code. |
| Abilities | each route names the ability its action checks. A Sanctum token's `laranail-settings:<ability>` abilities are a ceiling on top. |
| Owners | a settings route has a global form (`/stores/{store}/settings/…`) and an owner form (`/stores/{store}/owners/{alias}/{id}/settings/…`). `{alias}` is a morph alias from `http.owners` or the morph map, never a class name. |
| Locales | `?locale=fr` on settings routes; the write routes also take it. |
| Bodies | JSON. Every write may send `"reason"` (recorded in the audit trail and history). |
| ETags | setting responses carry `ETag`. Send it back as `If-Match` on `PUT` or `DELETE`: a mismatch is `412`; `*` matches any version. |
| Idempotency | every non-`GET` route accepts `Idempotency-Key`; a retry with the same key, actor, host, method and path within `http.idempotency.ttl` (24 hours) is answered from cache with `Idempotent-Replayed: true`. The same key with a different body answers `422` (`http-422`) and applies nothing. |
| Pagination | lists answer `{"data": [...], "meta": {"next_cursor": "…" or null}}`; pass `?cursor=<next_cursor>` and `?limit=` (1 to 200, default `http.page_size`). Cursors are opaque. |
| Visibility | values the actor may not view are left out of lists; a single setting answers `"redacted": true` without `value`. |
| Approvals | a write to a setting that needs approval answers `202` with the change request instead of the setting. |
| Errors | RFC 9457 `application/problem+json`; see [Problem types](#problem-types). |
| Rate limit | `http.rate_limit` per minute per user (or IP): `429`. |

## Stores and definitions

### `GET /stores`

`stores.index`, ability `view` per store (stores the actor may not view are left out). Lists the configured stores.

```json
{"data": [{"name": "settings", "mode": "polymorphic", "owners": ["user"], "layers": ["database", "runtime", "shipped"],
           "strict": false, "inherits": false, "fallback": null, "approvals": ["billing.*"]}],
 "meta": {"next_cursor": null}}
```

Status: `200`, `401`, `429`.

### `GET /stores/{store}/definitions`

`definitions.index`, ability `view`. The store's definitions (code, configuration and the definitions table), in key order: what a form is rendered from. Never a value; an encrypted definition's default is left out.

```json
{"data": [{"store": "settings", "key": "mail.from.address", "group": "mail", "category": "mail", "type": "email",
           "has_default": true, "default": "hello@example.com", "rules": ["email"], "encrypted": false, "sensitive": false,
           "public": false, "locked": false, "localised": false, "inherits": null, "overridable_by": [], "precedence": "store",
           "label": "Sender address", "description": null, "requires_verification": false, "requires_approval": false}],
 "meta": {"next_cursor": null}}
```

Status: `200`, `401`, `403`, `404` (`unknown-store`).

### `PUT /stores/{store}/definitions/{key}`

`definitions.update`, ability `manage_definitions`. Saves a definition in the definitions table (read back with `database` in `definitions.sources`). It only answers for keys no code or config definition covers.

```json
{"attributes": {"type": "string", "default": "free", "rules": ["in:free,pro"], "label": "Plan"}}
```

Answers `200` with the `Definition`. Status: `200`, `401`, `403`, `404`, `422` (`invalid-definition`, `validation`).

### `DELETE /stores/{store}/definitions/{key}`

`definitions.destroy`, ability `manage_definitions`. Deletes a database definition. Status: `204`, `401`, `403`, `404`.

## Settings

Every route here has an owner form, listed under it. `{key}` is a setting key (`[A-Za-z0-9_.]`, lower-cased).

### `GET /stores/{store}/settings`

Owner form: `GET /stores/{store}/owners/{alias}/{id}/settings`.

`settings.index`, ability `view` per key. A page of the scope's settings: each key the scope holds in any layer and each key a definition names, in key order, read as `GET …/settings/{key}` reads it. Keys the actor may not view, or sensitive keys it may not read, are left out. Query: `locale`, `limit`, `cursor` (the last key of the previous page).

```http
GET /api/settings/v1/stores/settings/owners/user/42/settings?limit=2
```

```json
{"data": [
  {"store": "settings", "scope": "user:42", "key": "ui.density", "locale": "", "found": true, "layer": "database", "from": "user:42", "version": 1, "redacted": false, "value": "compact"},
  {"store": "settings", "scope": "user:42", "key": "ui.theme", "locale": "", "found": true, "layer": "database", "from": "global", "version": 0, "redacted": false, "value": "light"}
 ],
 "meta": {"next_cursor": "ui.theme"}}
```

`from` is the scope the value came from (here the global value, inherited); `version` is the address's own row (0: none). Status: `200`, `401`, `403`, `404` (`unknown-store`, `unknown-owner`), `409` (`owner-not-allowed`).

### `GET /stores/{store}/settings/{key}`

Owner form: `GET /stores/{store}/owners/{alias}/{id}/settings/{key}`.

`settings.show`, ability `view` (`view_sensitive` for a sensitive value). One setting, with its `ETag`.

```json
{"data": {"store": "settings", "scope": "global", "key": "site.name", "locale": "", "found": true,
          "layer": "database", "from": "global", "version": 2, "redacted": false, "value": "Acme"}}
```

A key with no value answers `200` with `"found": false`. A sensitive value the actor may not read answers `"redacted": true` without `value`. Status: `200`, `401`, `403`, `404`, `409`.

### `PUT /stores/{store}/settings/{key}`

Owner form: `PUT /stores/{store}/owners/{alias}/{id}/settings/{key}`.

`settings.update`, ability `update` (plus step-up verification where configured). Writes a value through the write pipeline.

```http
PUT /api/settings/v1/stores/settings/settings/site.name
If-Match: "4f1c…"
Idempotency-Key: 6c0d1e8e-…

{"value": "Acme Ltd", "reason": "INC-7"}
```

Answers `200` with the setting as now read (and its new `ETag`), or `202` with a `ChangeRequest` when the setting needs approval. Status: `200`, `202`, `401`, `403`, `404`, `409` (`approval-unavailable`, `store-not-writable`, `owner-not-allowed`), `412`, `422` (`invalid-value`, `validation`), `423`, `428`.

### `DELETE /stores/{store}/settings/{key}`

Owner form: `DELETE /stores/{store}/owners/{alias}/{id}/settings/{key}`.

`settings.destroy`, ability `forget`. Removes the address's own value, so reads fall through again. Takes `If-Match` and `reason`. Answers `204`, or `202` with a `ChangeRequest`. Status: `204`, `202`, `401`, `403`, `404`, `409`, `412`, `423`, `428`.

### `POST /stores/{store}/settings/{key}/lock`

Owner form: `POST /stores/{store}/owners/{alias}/{id}/settings/{key}/lock`.

`settings.lock`, ability `lock`. Locks the row against further writes. Body: `{"reason": "…"}` (optional). Answers `200` with the setting, or `202`. Status: `200`, `202`, `401`, `403`, `404`, `409`, `428`.

### `DELETE /stores/{store}/settings/{key}/lock`

Owner form: `DELETE /stores/{store}/owners/{alias}/{id}/settings/{key}/lock`.

`settings.unlock`, ability `unlock`. Unlocks the row. Answers `200` with the setting, or `202`. Status as for lock.

### `GET /stores/{store}/settings/{key}/explain`

Owner form: `GET /stores/{store}/owners/{alias}/{id}/settings/{key}/explain`.

`settings.explain`, ability `view`. How the read resolves: every layer and scope tried.

```json
{"data": {"found": true, "layer": "database", "from": "team:7", "redacted": false,
          "steps": ["settings / user:42 / (none) / database", "settings / team:7 / (none) / database"], "value": "dark"}}
```

Status: `200`, `401`, `403`, `404`.

### `GET /stores/{store}/settings/{key}/history`

Owner form: `GET /stores/{store}/owners/{alias}/{id}/settings/{key}/history`.

`settings.history`, ability `view_history`. The setting's versions, newest first, paginated (`limit`, `cursor`, `locale`). Payloads are as stored (encrypted values encrypted) and are removed for an actor without `view_sensitive`.

```json
{"data": [{"id": "01JC…", "store": "settings", "setting_ref": "site.name", "scope": "global", "key": "site.name", "locale": "",
           "operation": "set", "old_value": "\"Acme\"", "new_value": "\"Acme Ltd\"", "old_type": "string", "new_type": "string",
           "old_encrypted": false, "new_encrypted": false, "version": 3, "causer_type": "user", "causer_alias": "user",
           "causer_id": "1", "reason": "INC-7", "created_at": "2026-11-10T09:12:00+00:00"}],
 "meta": {"next_cursor": null}}
```

Status: `200`, `401`, `403`, `404`, `409` (`history-unavailable`).

### `POST /stores/{store}/settings/{key}/rollback`

Owner form: `POST /stores/{store}/owners/{alias}/{id}/settings/{key}/rollback`.

`settings.rollback`, ability `rollback` (verified by default). Writes the value of an earlier version as a new version.

```json
{"version": 2, "reason": "revert INC-7"}
```

Answers `200` with the setting as now read. Status: `200`, `401`, `403`, `404`, `409` (`history-unavailable`), `422`, `423`, `428`.

## Audit

### `GET /audit`

`audit.index`, ability `view_audit`. The audit trail, newest first. Entries never hold values. Filters: `store`, `scope` (`global` or `alias:id`), `key`, `action` (an `AuditAction` value such as `set`, `forget`, `rollback`), `outcome` (`allowed`, `denied`, `pending`, `failed`), `since`, `until` (dates); plus `limit`, `cursor`.

```json
{"data": [{"id": "01JC…", "occurred_at": "2026-11-10T09:12:00+00:00", "action": "set", "outcome": "allowed",
           "store": "settings", "scope": "global", "key": "site.name", "locale": "", "version_from": 2, "version_to": 3,
           "actor_type": "user", "actor_alias": "user", "actor_id": "1", "channel": "rest"}],
 "meta": {"next_cursor": "…"}}
```

Status: `200`, `401`, `403`, `422` (`invalid-identifier` for a bad filter).

## Change requests

### `GET /stores/{store}/changes`

`changes.index`. An actor holding `approve` sees every request of the store; others see their own. Filters: `status` (`pending`, `approved`, `applied`, `rejected`, `cancelled`, `stale`, `expired`), `key`; plus `limit`, `cursor`. Status: `200`, `401`, `404`, `422`.

### `POST /stores/{store}/changes`

`changes.store`, the ability of the write being proposed (`update` for `set`). Opens a change request whether or not the setting needs approval.

```json
{"key": "billing.price", "value": 49, "owner": {"alias": "team", "id": "7"}, "locale": null, "operation": "set", "reason": "Q3 pricing"}
```

`operation` is `set` (default), `forget`, `lock` or `unlock`; `owner` is omitted for the global value. Answers `202` with the `ChangeRequest`:

```json
{"data": {"id": "01JC…", "store": "settings", "scope": "team:7", "key": "billing.price", "locale": "", "operation": "set",
          "encrypted": false, "base_version": 4, "status": "pending", "requested_by": {"type": "user", "alias": "user", "id": "3"},
          "approvals": [], "decided_by": null, "decided_at": null, "reason": "Q3 pricing", "decision_reason": null,
          "expires_at": "2026-11-17T09:12:00+00:00", "created_at": "2026-11-10T09:12:00+00:00"}}
```

Status: `202`, `401`, `403`, `404`, `409` (`approval-unavailable`), `422`.

### `GET /stores/{store}/changes/{id}`

`changes.show`, visible as in the listing. Status: `200`, `401`, `404` (`unknown-change-request`).

### `POST /stores/{store}/changes/{id}/approve`

`changes.approve`, ability `approve`; the requester cannot approve their own request unless `approvals.allow_self`. Body: `{"reason": "…"}`. When the approvals reach `approvals.min_approvals`, the change is applied against its base version (or becomes `stale` when the setting changed meanwhile). Answers `200` with the `ChangeRequest`. Status: `200`, `401`, `403`, `404`, `409` (`change-request-closed`), `428`.

### `POST /stores/{store}/changes/{id}/reject`

`changes.reject`, ability `approve`. Body: `{"reason": "…"}`. Answers `200`. Status as for approve.

### `POST /stores/{store}/changes/{id}/cancel`

`changes.cancel`, the requester only. Body: `{"reason": "…"}`. Answers `200`. Status as for approve.

## Categories

### `GET /stores/{store}/categories`

`categories.index`, ability `view`. The store's categories, parents before children. Status: `200`, `401`, `403`, `404`.

```json
{"data": [{"slug": "mail", "store": "settings", "title": "Mail", "titles": {}, "parent": null, "description": "Sender name and address.",
           "icon": "envelope", "sort_order": 0, "system": true}],
 "meta": {"next_cursor": null}}
```

### `POST /stores/{store}/categories`

`categories.store`, ability `manage_categories`.

```json
{"slug": "billing", "title": "Billing", "parent": "general", "description": null, "icon": "credit-card", "sort_order": 3, "titles": {"fr": "Facturation"}}
```

Answers `201` with the `Category`. Status: `201`, `401`, `403`, `404`, `422` (`category-cycle`, `category-store-mismatch`, `validation`).

### `PATCH /stores/{store}/categories/{slug}`

`categories.update`, ability `manage_categories`. `{"title": "…"}` renames; `{"parent": "…"}` moves (`null` to the top level). Answers `200` with the `Category`. Status: `200`, `401`, `403`, `404` (`unknown-category`), `422`.

### `DELETE /stores/{store}/categories/{slug}`

`categories.destroy`, ability `manage_categories`. Status: `204`, `401`, `403`, `404`.

## Persisted configuration

These need `configuration.persisted`; see [Persisted configuration](persisted-configuration.md).

### `GET /configuration`

`configuration.index`, ability `manage_stores`. Persisted stores and layers: `[{"kind": "store", "name": "tenant_settings", "config": {…}}]`. Status: `200`, `401`, `403`.

### `PUT /configuration/{kind}/{name}`

`configuration.update`, ability `manage_stores` (verified by default). `{kind}` is `stores` or `layers`.

```json
{"config": {"layers": ["database"], "owners": ["team"]}}
```

Answers `200` with the `ConfigurationEntry`. Status: `200`, `401`, `403`, `422` (`invalid-configuration`), `428`.

### `DELETE /configuration/{kind}/{name}`

`configuration.destroy`, ability `manage_stores`. Status: `204`, `401`, `403`, `428`.

## Import and export

### `POST /stores/{store}/export`

`transfer.export`, ability `export` (`view_sensitive` for `plaintext`). Body: `{"mode": "redacted" | "ciphertext" | "plaintext"}` (default `redacted`). Answers `200` with the `ExportDocument`:

```json
{"data": {"format": "laranail/settings", "version": 1, "store": "settings", "mode": "redacted",
          "categories": [{"slug": "mail", "title": "Mail", "parent": null}],
          "settings": [{"scope": "global", "key": "site.name", "locale": "", "value": "Acme", "type": "string"}]}}
```

Status: `200`, `401`, `403`, `404`.

### `POST /stores/{store}/import`

`transfer.import`, ability `import` (verified by default). A **dry run** unless `"apply": true`.

```json
{"document": {"format": "laranail/settings", "version": 1, "store": "settings", "mode": "redacted", "categories": [], "settings": []}, "apply": true}
```

Answers `200` with the `ImportReport` (`applied`, `created`, `updated`, `unchanged`, `skipped`, `categories`, `proposed`). Status: `200`, `401`, `403`, `404`, `422` (`invalid-import`), `428`.

### `POST /stores/{store}/diff`

`transfer.diff`, ability `export`. Body: `{"document": {…}}`. Answers `200` with the `StoreDiff` (`added`, `removed`, `changed`, `unchanged`, `unknown`: keys only). Status: `200`, `401`, `403`, `404`, `422`.

## Problem types

Every error is a problem document whose `type` is `https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#<type>`, a section of the [exceptions reference](exceptions.md#problem-types) (`http-<status>` types point at `#http-status`):

```json
{"type": "https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#locked", "title": "Locked", "status": 423,
 "detail": "Setting [billing.currency] in store [settings] is locked for scope [global].", "instance": "/api/settings/v1/stores/settings/settings/billing.currency"}
```

| Type | Status | Raised by |
|---|---|---|
| `access-denied` | 403 | `AccessDenied` |
| `actor-required` | 401 | `ActorRequired` |
| `unauthenticated` | 401 | the host's authentication middleware |
| `verification-required` | 428 | `VerificationRequired`: verify (confirm the password) and retry |
| `unknown-store` | 404 | `UnknownStore` |
| `unknown-setting` | 404 | `UnknownSetting` (a strict store's undefined key) |
| `unknown-owner` | 404 | `UnknownOwner` (an alias or record the owner resolver cannot find) |
| `unknown-category` | 404 | `UnknownCategory` |
| `unknown-change-request` | 404 | `UnknownChangeRequest` |
| `invalid-value` | 422 | `InvalidSettingValue` |
| `invalid-identifier` | 422 | `InvalidIdentifier`, `InvalidKey` (a key, store, locale, alias or filter) |
| `invalid-import` | 422 | `InvalidImport` |
| `invalid-definition` | 422 | `InvalidDefinition` |
| `invalid-configuration` | 422 | `InvalidConfiguration` |
| `type-mismatch` | 422 | `TypeMismatch` |
| `category-cycle` | 422 | `CategoryCycle` |
| `category-store-mismatch` | 422 | `CategoryStoreMismatch` |
| `validation` | 422 | request validation; the body adds `errors` |
| `locked` | 423 | `LockedSetting` |
| `precondition-failed` | 412 | `ConcurrentModification` (`If-Match` did not match) |
| `approval-unavailable` | 409 | `ApprovalUnavailable` |
| `change-request-closed` | 409 | `ChangeRequestClosed` |
| `owner-not-allowed` | 409 | `OwnerNotAllowed` |
| `store-not-writable` | 409 | `StoreNotWritable` |
| `history-unavailable` | 409 | `HistoryUnavailable` |
| `too-many-requests` | 429 | the rate limit |
| `unavailable` | 503 | `DriverUnavailable` |
| `http-<status>` | that status | any other HTTP exception (`http-404` for an unknown route) |
| `settings-error` | 500 | any other package exception (its message is not exposed) |

Detail messages name stores, keys and scopes, never a value.

---

[← Docs index](../../README.md#documentation)
