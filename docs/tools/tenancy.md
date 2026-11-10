# Tenancy

Three modes keep each tenant's settings apart: none, a tenant column, or a database per tenant, chosen with `tenancy.mode`.

## Modes

| `tenancy.mode` | What changes | For |
|---|---|---|
| `none` (default) | nothing: one set of settings | single-tenant applications |
| `column` | the values, categories and audit tables get a `tenant` column; every row is stamped with the current tenant and every query is limited to it | one database shared by every tenant |
| `database` | no column: the tenancy package switches the connection, and the store follows it | a database per tenant |

The mode is migration-time, like the other [schema switches](schema-switches.md): set it before
`install`, and the schema file records it. In `column` mode the tenant leads the unique keys
(`unique(tenant, scope, key, locale)` on values, `unique(tenant, store, slug)` on categories), so
two tenants can hold the same key, and `''` is the central application's rows. The column is
`string(64)` and binary-collated, like `scope`.

Definitions are shared by every tenant: they describe settings, they hold no tenant's values. In
`column` mode, synced definitions link to the central (`''`) categories.

## Who the current tenant is

```php
// config/laranail/settings.php
'tenancy' => [
    'mode'          => 'column',
    'discriminator' => 'auto',
],
```

`discriminator` is `auto` (stancl/tenancy when installed, else spatie/laravel-multitenancy, else
none), `null`, `stancl`, `spatie`, or a class implementing
`Simtabi\Laranail\Settings\Contracts\TenantDiscriminator`:

```php
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;

final readonly class TeamTenant implements TenantDiscriminator
{
    public function __construct(private CurrentTeam $team) {}

    public function current(): ?string
    {
        return $this->team->id();   // null outside a tenant
    }
}
```

Neither tenancy package is required: both are `suggest`ed and detected with `class_exists`. A key
is an integer, a string or a `Stringable` of 1 to 64 characters; anything else raises
`InvalidConfiguration` rather than being truncated, because two tenants must never share a key.
The discriminator is bound `scoped`, so Octane requests and queued jobs each ask again.

## What is kept apart

| | `column` | `database` |
|---|---|---|
| values (reads, writes, forget, flush) | filtered on `tenant` | by connection |
| categories | filtered on `tenant` | by connection |
| audit trail (`Settings::audit()`) | the current tenant's rows | by connection |
| records preloaded in memory | per tenant | per tenant |
| the Eloquent models | global scope on `tenant`, stamped on create | by connection |

Records preloaded during one request or job are kept per tenant in every mode, so a command that
loops over tenants never answers one tenant's read from another's. Schema readiness is
re-checked on the tenancy packages' switch events (`readiness.flush_on`).

`prune-audit` applies the retention to every tenant's rows: retention is one policy for the whole
application.

## Database per tenant

Publish the package migration into the folder your tenancy package migrates tenants from (for
stancl/tenancy, `database/migrations/tenant`), leave the store's `connection` null so it follows
the tenant connection, and seed each tenant with the tenancy package's own seed command. The file
layers (`runtime`, `shipped`) are application-wide in every mode.

---

[← Docs index](../../README.md#documentation)
