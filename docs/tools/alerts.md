# Alerts

Laravel notifications sent when a settings event matches a rule: mail, the database channel and Slack, throttled, and never carrying a value.

## Turning them on

Alerts are off by default. Turn them on and add rules in `config/laranail/settings.php`:

```php
'alerts' => [
    'enabled'  => true,
    'cache'    => null,       // the throttle's cache store; null for the default
    'throttle' => 300,        // seconds a rule stays quiet about one key; 0 for never
    'rules'    => [
        [
            'events'         => ['SettingSaved', 'SettingForgotten', 'SettingRolledBack'],
            'keys'           => ['mail.*', 'billing.*'],
            'stores'         => null,
            'sensitive_only' => false,
            'outcomes'       => null,
            'notify'         => [
                'mail'     => ['ops@example.com'],
                'slack'    => env('SETTINGS_ALERTS_SLACK_WEBHOOK'),
                'database' => ['type' => App\Models\User::class, 'id' => 1],
            ],
            'queue' => 'notifications',
        ],
        [
            'events'   => ['AccessWasDenied', 'VerificationWasRequired'],
            'outcomes' => ['denied'],
            'notify'   => ['mail' => ['security@example.com']],
        ],
    ],
],
```

`listeners.alerts` (on by default) can switch the listener off without removing the rules.

## Rules

| Key | Meaning |
|---|---|
| `events` | event short names (`SettingSaved`) or classes; required. Any event in the [events](events.md) tables except the `-ing` ones and `LayerSkipped` |
| `keys` | globs matched against the key (`mail.*`); null for any. An event without a key never matches a rule with `keys` |
| `stores` | store names; null for any |
| `sensitive_only` | only keys whose definition is sensitive or encrypted |
| `outcomes` | `allowed` (changes) or `denied` (refusals); null for any |
| `notify.mail` | addresses, sent as an on-demand notification |
| `notify.slack` | an incoming-webhook URL; used only when `laravel/slack-notification-channel` is installed |
| `notify.database` | a model class using `Notifiable` and its key; the alert goes to its `notifications` |
| `queue` | the queue to send on; null sends at once |

A malformed rule raises `InvalidConfiguration` naming its config path (`laranail.settings.alerts.rules.0.events`) when the first event fires.

## What an alert says

`Notifications\SettingsAlert` carries an `AlertContext`: the event, store, key, scope, locale, actor (`user:<alias>:<id>`, `system`), outcome, time and the event's counts. It is built from the event's identifiers, so no channel can show a setting's value; the mail ends by saying so. `toArray()` / `toDatabase()` return the context as an array.

## Throttling

After a rule alerts about one setting (one store, for events without a key) it stays quiet about it for `alerts.throttle` seconds, so a burst of writes sends one alert. The throttle is an atomic `add()` in the configured cache. When the cache fails, the alert is sent anyway and the failure is logged.

## Testing

```php
Notification::fake();

settings()->set('mail.host', 'smtp.example.com');

Notification::assertSentOnDemand(SettingsAlert::class, fn (SettingsAlert $alert) => $alert->context->key === 'mail.host');
```

Rules are read when the listener is first built; listeners are registered at boot, so set `alerts.enabled` in configuration (or `.env`, `LARANAIL_SETTINGS_ALERTS`) rather than at runtime.

---

[← Docs index](../../README.md#documentation)
