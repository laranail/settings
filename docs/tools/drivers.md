# Drivers and layers

A store reads through ordered layers; each layer is backed by one of five built-in drivers or a custom one.

## Built-in drivers

| Driver | Writable | Holds | Options |
|---|---|---|---|
| `database` | yes, unless `writable => false` | every scope and locale, in the store's table | `connection` (null = default) |
| `json` | yes, unless `writable => false` | every scope and locale | `disk` (private), `path` containing `{store}` |
| `php` | no | global, unlocalised `key => value` | `path`, relative to the application root |
| `config` | no | global, unlocalised values under a config prefix | `prefix` |
| `array` | yes | values for the current process only | — |

Reads try each layer in order and stop at the first that holds a value. Writes go to the first writable layer.

## The database driver

Described in [Database](database.md): one table per store, version-checked writes and
`DriverUnavailable` when the database is gone.

## The JSON driver

One file per store. Writes go to a temporary file that is then moved over the real one, which is an atomic rename on local disks. There is no cross-process lock, so two processes writing at the same moment can lose one update; the runtime layer is meant for the install window, not for heavy concurrent writing. A public disk is refused.

## The PHP driver

A file returning flat, dotted keys:

```php
<?php

return [
    'mail.from.address' => 'hello@example.com',
    'site.tags'         => ['shop', 'blog'],
];
```

A missing file is an empty layer. The file is read once per process and opcache keeps it cheap.

## Custom drivers

Register a closure on the factory, in a service provider's `boot()`. Built-in names cannot be replaced, and a driver name never becomes a class or method name.

```php
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Data\LayerConfig;

app(DriverFactory::class)->extend('dynamo', fn (LayerConfig $layer) => new DynamoDriver(/* ... */));
```

A custom driver implements `Simtabi\Laranail\Settings\Contracts\Driver`: `load()`, `put()`, `forget()` and `isWritable()`.

---

[← Docs index](../../README.md#documentation)
