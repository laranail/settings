# Ship defaults with the application

Keep defaults in a versioned PHP file instead of `.env`, and let installs override them.

Create `settings/defaults.php` in the application root:

```php
<?php

return [
    'mail.from.address' => 'hello@example.com',
    'site.name'         => 'Acme',
];
```

The default store's `shipped` layer reads it. A value written later (`Settings::set('site.name', 'Acme Ltd')`) lands in the writable layer above it and wins; `forget()` falls back to the shipped value. Changing the file in a release changes the value for every install that never overrode it.

See [Drivers and layers](../tools/drivers.md) for the layer options.

---

[← Docs index](../../README.md#documentation)
