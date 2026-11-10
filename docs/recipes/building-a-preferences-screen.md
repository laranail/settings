# Build a per-user preferences screen

Let each user choose their own theme, language and notification options, falling back to the application's defaults until they do.

## Define the preferences

```php
// a store for user preferences, routed by the `user` morph alias
'stores' => [
    'settings' => [...],
    'preferences' => ['layers' => ['database'], 'owners' => ['user'], 'inherits' => true],
],
```

```php
$registry->define('ui.theme')->inStore('preferences')->ofType('string')->rules(['in:light,dark,system'])->withDefault('system')->labelled('Theme')->register();
$registry->define('ui.locale')->inStore('preferences')->ofType('string')->withDefault('en')->labelled('Language')->register();
$registry->define('notify.digest')->inStore('preferences')->ofType('boolean')->withDefault(true)->labelled('Weekly digest')->register();
```

The `User` model uses `HasSettings` and implements `Settable`, with `user` in the morph map.

## Read them into the screen

```php
public function edit(Request $request, Settings $settings): View
{
    $prefs = $settings->for($request->user())->as($request->user());

    return view('preferences', [
        'definitions' => $prefs->definitions(),
        'values'      => $prefs->many(['ui.theme', 'ui.locale', 'notify.digest']),
    ]);
}
```

`many()` returns each value or its definition default, so a new user sees the defaults.

## Save the form

```php
public function update(UpdatePreferencesRequest $request, Settings $settings): RedirectResponse
{
    $settings->for($request->user())->as($request->user())->setMany($request->validated());

    return back()->with('status', 'Preferences saved.');
}
```

Let users change only their own values with a gate that compares the owner:

```php
Gate::define('laranail-settings.update', fn (User $user, Subject $subject) =>
    $subject->owner?->alias === 'user' && (string) $subject->owner->id === (string) $user->getKey());
```

## Reset to the default

```php
$settings->for($user)->as($user)->forgetMany(['ui.theme', 'ui.locale']);
```

Forgetting the user's own value lets reads fall back to the default again.

## Read them on every request without a query each

```php
User::query()->withSettings()->paginate();     // listing pages: one read per store
$settings->for(auth()->user())->get('ui.theme'); // cached per scope after the first read
```

A client-side screen does the same through `GET` and `PUT /stores/preferences/owners/user/{id}/settings/{key}`; see [Read settings from an SPA or a mobile app](consuming-from-an-spa-or-mobile-app.md).

---

[← Docs index](../../README.md#documentation)
