# Read settings from an SPA or a mobile app

Serve a single-page or mobile client the settings it needs: cookie sessions for a first-party SPA, Sanctum tokens for a mobile app, and conditional requests to keep traffic down.

## A first-party SPA: Sanctum cookies

```php
// config/laranail/settings.php
'authentication' => ['resolver' => 'sanctum'],
'http'           => ['enabled' => true, 'middleware' => ['api', 'auth:sanctum']],
```

The SPA calls `/sanctum/csrf-cookie` once, logs in, then sends `credentials: 'include'` and the `X-XSRF-TOKEN` header on writes. Requests act as the logged-in user; the gates decide.

## A mobile app: a token with a ceiling

```php
$token = $user->createToken('ios', ['laranail-settings:view', 'laranail-settings:update'])->plainTextToken;
```

```http
GET /api/settings/v1/stores/settings/owners/user/42/settings?limit=200
Authorization: Bearer 12|kR9…
Accept: application/json
```

The token can read and update, never `forget`, `rollback` or `approve`, whatever the gates allow. See [Call the REST API with Sanctum](calling-the-rest-api-with-sanctum.md).

## Load everything a screen needs in one call

`GET …/settings` returns every key the user can see, global defaults included through definitions, in pages of up to 200. For a user's own preferences use the owner form; values inherited from the team or the global value come back with `"from"` naming where they came from.

```kotlin
val page = api.get("stores/settings/owners/user/$userId/settings?limit=200")
val prefs = page.data.associate { it.key to it.value }
```

## Cache with ETags

Each single setting carries an `ETag`. Keep it with the cached value and send it back as `If-Match` when writing, so a stale client cannot overwrite a newer value: it gets `412 precondition-failed` and re-reads.

## Retry writes safely

Mobile networks drop responses. Send an `Idempotency-Key` with every write and reuse it on retry: the server answers a replay from cache (`Idempotent-Replayed: true`) for `http.idempotency.ttl` seconds instead of writing twice.

```swift
var request = URLRequest(url: url.appending(path: "stores/settings/owners/user/\(id)/settings/ui.theme"))
request.httpMethod = "PUT"
request.setValue(pendingWrite.idempotencyKey, forHTTPHeaderField: "Idempotency-Key")
request.httpBody = try JSONEncoder().encode(["value": "dark"])
```

## Handle the answers

`401` means log in again; `429` means back off (`http.rate_limit` per minute); `202` means the change waits for approval, so show it as pending; anything else is a [problem document](../tools/rest-api-reference.md#problem-types) whose `detail` is safe to show.

---

[← Docs index](../../README.md#documentation)
