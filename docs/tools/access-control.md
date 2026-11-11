# Access control

Actors, the 18 abilities, the authorizers that decide them, guarded locks, sensitive reads and step-up verification.

## When access is checked

A handle with an actor checks every operation, reads included. A handle without one runs as trusted code, exactly as before, and is audited as `system`.

```php
use Simtabi\Laranail\Settings\Facades\Settings;

Settings::as($user)->set('mail.from.address', 'ap@acme.test');   // authorized and audited
Settings::asSystem('nightly sync')->set('feeds.last_run', now()); // trusted, audited with the reason
Settings::set('site.name', 'Acme');                               // trusted, audited as system
```

Set `authorization.require_actor` to `true` to refuse calls without an actor (`ActorRequired`). `asSystem()` stays allowed, so trusted code says so explicitly.

## Actors

`Simtabi\Laranail\Settings\Data\Actor` is the authentication object.

| Field | Meaning |
|---|---|
| `type` | `ActorType`: `user`, `system`, `token`, `service` |
| `id`, `alias` | the authenticatable's key and morph alias |
| `user` | the `Authenticatable`; never serialised |
| `channel` | `Channel`: `php`, `rest`, `console`, `queue` |
| `abilities` | token abilities; `null` means no token limits the actor |
| `verifiedAt` | when the actor last passed step-up verification |
| `context` | `ActorContext`: ip, user agent (cut to 255 characters), request id, reason |

Build one with `Actor::user($user)` or `Actor::system($reason)`, or let a resolver find it with `Settings::asCurrentActor()`. A serialised actor keeps its identifiers and drops the model, so a queued job can carry one.

## Resolvers

`asCurrentActor()` asks the resolver named by `authentication.resolver`:

| Resolver | Behaviour |
|---|---|
| `guard` (default) | the first guard in `authentication.guards` with a user; empty means the default guard |
| `sanctum` | as `guard` (default guard `sanctum`), and copies the current token's abilities into the actor; needs `laravel/sanctum` |
| a class name | any class implementing `Contracts\ActorResolver` |

When nobody is authenticated, `asCurrentActor()` raises `ActorRequired` rather than running as trusted code.

## Abilities

`Enums\Ability`: `view`, `view_sensitive`, `update`, `forget`, `lock`, `unlock`, `override_lock`, `approve`, `view_history`, `rollback`, `view_audit`, `export`, `import`, `promote`, `manage_categories`, `manage_definitions`, `manage_stores`. This release checks the first seven; the rest are reserved for the features that use them.

| Operation | Abilities |
|---|---|
| `get`, `lookup`, `has`, typed getters | `view`, plus `view_sensitive` for a sensitive key |
| `explain` | `view`; a sensitive value the actor may not see is redacted, not refused |
| `set` | `update`, plus `override_lock` over a lock (below) |
| `forget` | `forget`, plus `override_lock` over a lock |
| `lock` / `unlock` | `lock` / `unlock` |

## Authorizers

`authorization.driver` picks the `Contracts\Authorizer`:

| Driver | Decides by |
|---|---|
| `gate` (default) | Laravel's gate `laranail-settings.<ability>`, passed the `Subject` |
| `permissions` | `$user->can()` with the name mapped in `authorization.permissions` (placeholders `{ability}`, `{store}`, `{key}`) |
| a class name | any class implementing `Authorizer` |
| a list | each in order; the first that does not abstain decides |

```php
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\Subject;

Gate::define('laranail-settings.view', fn (User $user, Subject $subject) => true);
Gate::define('laranail-settings.update', fn (User $user, Subject $subject) => $user->isAdmin());
```

An ability nobody defined abstains, and `authorization.undefined` decides it: `deny` (the default, fails closed) or `allow`. A `Gate::before` callback still runs for an undefined ability, so a super-admin rule keeps working.

An authorizer returns a `Decision`: `allow()`, `deny($reason)`, `requiresVerification()`, `requiresApproval()` or `abstain()`. A denial raises `AccessDenied`, which carries the ability, store and key and never a value.

## Tokens are a ceiling

A token-bearing actor must also hold the ability in its token: `settings:<ability>`, `settings:*` or `*`. The token never grants anything on its own; the authorizer must allow too.

## Guarded locks

A locked row refuses writes from everyone, as before. A store can let one privileged actor through:

```php
'stores' => [
    'settings' => [
        'layers' => ['runtime', 'shipped'],
        'locks'  => ['overridable' => true],
    ],
],
```

With `locks.overridable` on, an actor allowed `override_lock` may `set` or `forget` a locked row; the lock stays. Without it, or for an actor without `override_lock`, the write raises `LockedSetting`. Trusted code never overrides a lock: unlock it first.

## Sensitive settings

Mark a definition `sensitive()` (or `'sensitive' => true` in config); `encrypted()` implies it. Reading one needs `view_sensitive` as well as `view`, and `explain()` redacts its value: `Explanation::isRedacted()` is true, `Lookup::value()` throws, and the layer and steps stay visible.

## Step-up verification

A write needs a recent identity check when its ability is listed in `verification.abilities` (default `override_lock`, `rollback`, `import`, `manage_stores`), when the definition says `requiresVerification()`, or when an authorizer returns `requiresVerification()`. Reads are never step-up checked by the list or the definition flag.

| `verification.driver` | Verified when |
|---|---|
| `password_confirmation` (default) | the session's `auth.password_confirmed_at` is within `auth.password_timeout`; otherwise the actor's `verifiedAt` within the same timeout |
| `actor` | the actor's `verifiedAt` is within `verification.ttl` seconds |
| `none` | always |
| a class name | any class implementing `Contracts\Verifier` |

An unverified actor gets `VerificationRequired`. Send the user through Laravel's `password.confirm` route, or set `verifiedAt` on an actor that verified elsewhere (a fresh TOTP), and retry.

## Approvals

A definition can say `requiresApproval()`, and a store can name key globs in `approvals.keys`. A write to such a setting opens a change request instead of writing; see [Approvals](approvals.md). A store without an active database layer cannot hold change requests, so there the write raises `ApprovalUnavailable`, as it does when an authorizer answers that the ability needs approval. An actor not allowed to write at all gets `AccessDenied` first.

---

[← Docs index](../../README.md#documentation)
