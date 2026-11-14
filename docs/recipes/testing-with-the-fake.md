# Test code that uses settings

Swap the engine for one over in-memory layers and assert on what was written.

```php
use Simtabi\Laranail\Settings\Facades\Settings;

it('saves the chosen theme', function () {
    $settings = Settings::fake();

    $this->post('/preferences', ['theme' => 'dark']);

    $settings->assertSaved('ui.theme', 'dark');
    $settings->assertNotSaved('ui.density');
});
```

The fake runs the real engine, so definitions, validation, casting and access control still apply, and nothing is written to disk. Install it before the code under test resolves `Settings`: anything resolved earlier keeps the real instance.

| Assertion | Passes when |
|---|---|
| `assertSaved($key)` | the key was saved |
| `assertSaved($key, $value)` | the key was saved with exactly this value |
| `assertNotSaved($key)` | the key was never saved |
| `assertForgotten($key)` | the key was forgotten |
| `assertAudited($action, $outcome = null, $key = null)` | an audit entry for this `AuditAction` exists, optionally with this outcome and key |
| `assertDenied($key, $ability = null)` | an access to this key was refused, optionally for this `Ability` |

Actors work in the fake exactly as in the engine, so gates you define in the test apply:

```php
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Enums\Ability;

it('refuses a non-admin', function () {
    $settings = Settings::fake();
    Gate::define('laranail-settings.update', fn (User $user) => $user->isAdmin());

    expect(fn () => $settings->as($member)->set('mail.from.address', 'x@acme.test'))
        ->toThrow(AccessDenied::class);

    $settings->assertDenied('mail.from.address', Ability::Update);
});
```

`auditEntries()` returns every recorded entry.

## Fake or override?

Use the fake when the test is about what the code **writes**: it replaces every layer with memory and records each save, forget and audit entry for the assertions above. Use a [runtime override](../tools/runtime-overrides.md) when the test only needs one value to **read** differently and the engine can stay real:

```php
it('hides the beta banner when the feature is off', function () {
    Settings::override('features.beta', false);

    $this->get('/')->assertDontSee('Try the beta');
});
```

An override is never recorded as saved, so with the fake installed, `assertNotSaved()` still passes for an overridden key. The two combine: `Settings::fake()` first, then `Settings::override()` or `Settings::withOverrides()` on top. The [override recipe](overriding-a-setting-for-one-request.md) covers overrides outside tests.

---

[← Docs index](../../README.md#documentation)
