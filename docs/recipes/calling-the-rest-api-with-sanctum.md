# Call the REST API with Sanctum

Give a client a Sanctum token that may read settings and change only some of them.

## Configure

```bash
composer require laravel/sanctum
```

```php
// config/laranail/settings.php
'authentication' => ['resolver' => 'sanctum'],
'http'           => ['enabled' => true],      // middleware defaults to ['api', 'auth:sanctum']
```

Define the gates (or use the `permissions` authorizer), as for any actor:

```php
Gate::define('laranail-settings.view', fn (User $user) => true);
Gate::define('laranail-settings.update', fn (User $user) => $user->is_admin);
```

## Issue a token

```php
$token = $user->createToken('deploy-bot', ['settings:view', 'settings:update'])->plainTextToken;
```

A token's `settings:<ability>` abilities are a ceiling on top of the gates: the token above can never `forget`, `rollback` or `approve`, even for an admin. `settings:*` lifts the ceiling.

## Call it

```bash
curl -s https://app.example/api/settings/v1/stores/settings/settings/site.name \
     -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

curl -s -X PUT https://app.example/api/settings/v1/stores/settings/settings/site.name \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -H "If-Match: $ETAG" -H "Idempotency-Key: $(uuidgen)" \
     -d '{"value": "Acme Ltd", "reason": "rebrand"}'
```

`412` means the setting changed since you read its ETag; read it again. `428` means the ability needs step-up verification (`verification.abilities`): use the `actor` verifier and send a fresh `verifiedAt`, or leave such abilities to people. Generate a client from `php artisan laranail::settings.openapi`.

See [REST API](../tools/rest-api.md).

---

[← Docs index](../../README.md#documentation)
