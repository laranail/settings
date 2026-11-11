# REST API quick start

Turn the API on, authenticate, and make a first read and write.

## Enable it

```dotenv
LARANAIL_SETTINGS_HTTP=true
```

The routes live under `/api/settings/v1` and use `['api', 'auth:sanctum']` when Sanctum is
installed, else `['api', 'auth']`. Change the prefix with `LARANAIL_SETTINGS_HTTP_PREFIX` if the
application already has routes under `/api/settings`.

## Authenticate and allow

The API runs as the authenticated user, never as trusted code, so define the gates that user needs:

```php
// app/Providers/AppServiceProvider.php, in boot()
Gate::define('laranail-settings.view', fn (User $user) => true);
Gate::define('laranail-settings.update', fn (User $user) => $user->is_admin);
```

For a token, install Sanctum and issue one:

```php
$token = $user->createToken('admin-ui', ['laranail-settings:view', 'laranail-settings:update'])->plainTextToken;
```

## First request

```bash
curl -s https://app.example/api/settings/v1/stores \
     -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

curl -si -X PUT https://app.example/api/settings/v1/stores/settings/settings/site.name \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"value": "Acme", "reason": "first run"}'
```

The write answers `200` with the setting and an `ETag` header; send it back as `If-Match` on the
next write so a concurrent change answers `412` instead of being overwritten. Errors are RFC 9457
problem details.

Next: [Call the REST API with Sanctum](calling-the-rest-api-with-sanctum.md) for token abilities and
idempotency, and the [REST API reference](../tools/rest-api-reference.md) for every route.

---

[← Docs index](../../README.md#documentation)
