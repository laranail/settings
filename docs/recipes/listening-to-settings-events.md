# Listen to settings events

React to changes with your own listener, using Laravel's event dispatcher.

## A listener

```php
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Settings\Events\SettingSaved;

Event::listen(SettingSaved::class, function (SettingSaved $event): void {
    if ($event->store === 'settings' && str_starts_with($event->key, 'mail.')) {
        Mail::purge(); // rebuild the mailer with the new settings
    }
});
```

Register it in a service provider's `boot()`. A queued listener (`ShouldQueue`) is safe: events carry identifiers only, so no value reaches the jobs table.

## Reading the new value

Events never carry the value. Read it through the API, as the actor the listener works for:

```php
Event::listen(SettingSaved::class, function (SettingSaved $event): void {
    $value = settings()->store($event->store)->asSystem('mail listener')->get($event->key);
});
```

An owner's change has a scope such as `user:42`; `Scope::fromString($event->scope)?->owner()` gives back the `OwnerRef` to pass to `for()`.

## Watching refusals

```php
use Simtabi\Laranail\Settings\Events\AccessWasDenied;

Event::listen(AccessWasDenied::class, function (AccessWasDenied $event): void {
    report(new RuntimeException(sprintf('Settings refused [%s] on [%s] (%s).', $event->ability->value, $event->subject->key, $event->refusal)));
});
```

For notifications without code, configure [alerts](../tools/alerts.md). Every event is listed in [Events](../tools/events.md).

---

[← Docs index](../../README.md#documentation)
