# Build your own admin UI on the REST API

Render a settings screen from the store's definitions, save each field with optimistic locking, and show who changed what, all over the JSON API.

## Turn the API on and grant the abilities

```php
// config/laranail/settings.php
'http' => ['enabled' => true],   // routes under /api/settings/v1
```

```php
// AppServiceProvider::boot()
Gate::define('laranail-settings.view', fn (User $user) => $user->is_staff);
Gate::define('laranail-settings.update', fn (User $user) => $user->is_admin);
Gate::define('laranail-settings.view_history', fn (User $user) => $user->is_admin);
Gate::define('laranail-settings.view_audit', fn (User $user) => $user->is_admin);
```

## Build the form from definitions

The definitions carry everything a field needs: type, label, description, category, rules, and whether the value is sensitive. Categories give the sections.

```js
const api = (path, init = {}) =>
  fetch(`/api/settings/v1${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init.headers },
    ...init,
  });

const [categories, definitions, values] = await Promise.all([
  api('/stores/settings/categories').then((r) => r.json()),
  api('/stores/settings/definitions?limit=200').then((r) => r.json()),
  api('/stores/settings/settings?limit=200').then((r) => r.json()),
]);

const byKey = Object.fromEntries(values.data.map((s) => [s.key, s]));
const sections = categories.data.map((category) => ({
  title: category.title,
  fields: definitions.data
    .filter((d) => d.category === category.slug)
    .map((d) => ({ ...d, current: byKey[d.key]?.value ?? d.default })),
}));
```

Map `type` to an input (`boolean` to a toggle, `integer` to a number field, `email` to an email field), and render `sensitive` fields as write-only: the listing leaves out values the user may not read. Follow `meta.next_cursor` when a store holds more than 200 keys.

## Save one field at a time, with its ETag

```js
async function save(key, value, reason) {
  const current = await api(`/stores/settings/settings/${key}`);
  const response = await api(`/stores/settings/settings/${key}`, {
    method: 'PUT',
    headers: { 'If-Match': current.headers.get('ETag'), 'Idempotency-Key': crypto.randomUUID() },
    body: JSON.stringify({ value, reason }),
  });

  if (response.status === 202) return { pending: (await response.json()).data };   // needs approval
  if (response.ok) return { saved: (await response.json()).data };
  throw await response.json();   // a problem document
}
```

Keep the ETag from the read that filled the form, rather than re-reading just before saving, when you want "someone else changed this" warnings: a `412 precondition-failed` then means the field changed since the user saw it.

## Show errors next to the field

| Problem `type` ends in | Show |
|---|---|
| `validation`, `invalid-value` | the message under the field (`errors` has per-field messages) |
| `locked` | the field as read-only, with a lock icon |
| `precondition-failed` | "changed by someone else", with a reload button |
| `verification-required` | a password confirmation, then retry the same request |
| `access-denied` | hide the save button for that field |

## Add history and an activity feed

```js
const history = await api(`/stores/settings/settings/${key}/history?limit=20`).then((r) => r.json());
const activity = await api(`/audit?store=settings&since=${weekAgo}`).then((r) => r.json());
```

A "restore this version" button is `POST /stores/settings/settings/{key}/rollback` with `{"version": n}`.

Every route is in the [REST API reference](../tools/rest-api-reference.md); the same screen can be built server-side with `definitions()` and `all()` from the [Settings API](../tools/settings-api.md).

---

[← Docs index](../../README.md#documentation)
