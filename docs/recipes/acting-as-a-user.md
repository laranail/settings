# Act as a user

Check and audit settings changes made on a user's behalf with gates you already have.

Define the gates in a service provider's `boot()`:

```php
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\Subject;

Gate::define('laranail-settings.view', fn (User $user, Subject $subject) => true);
Gate::define('laranail-settings.update', fn (User $user, Subject $subject) => $user->isAdmin());
```

Then act as the user in a controller or action:

```php
use Simtabi\Laranail\Settings\Contracts\Settings;

public function update(UpdateMailRequest $request, Settings $settings): RedirectResponse
{
    $settings->asCurrentActor()
        ->because($request->string('reason'))
        ->set('mail.from.address', $request->validated('address'));

    return back();
}
```

A non-admin gets `AccessDenied`; both outcomes land in the audit trail with the reason. In a queued job, serialise the `Actor` (the model is dropped) or call `asSystem('why')`.

See [Access control](../tools/access-control.md) for abilities, tokens and verification, and [Audit and logging](../tools/audit-and-logging.md) for what is recorded.

---

[← Docs index](../../README.md#documentation)
