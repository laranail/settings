# Persisted configuration

Stores, layers and definitions saved in the database and edited through the PHP and REST APIs, beside the configuration file.

## Turning it on

```php
// config/laranail/settings.php
'configuration' => [
    'persisted' => true,                              // read and write settings_stores
    'sources'   => ['config', 'database', 'runtime'], // merge order, later wins
],
'definitions' => ['sources' => ['code', 'config', 'database']], // read saved definitions
```

The migration always creates `settings_stores` and `settings_definitions`.

## Editing

```php
$editor = $settings->as($admin)->persistedConfiguration();

$editor->saveLayer('scratch', ['driver' => 'array']);
$editor->saveStore('reports', ['layers' => ['runtime'], 'owners' => ['team']]);
$editor->entries();                                   // list<ConfigurationEntry>
$editor->deleteStore('reports');

$editor->saveDefinition('site.theme', ['type' => 'string', 'default' => 'light', 'rules' => ['in:light,dark']]);
$editor->deleteDefinition('site.theme');
```

Store and layer entries take the shape the configuration file gives `stores.<name>` and `layers.<name>`; definitions take the attributes of `definitions.<key>`. Each change is validated exactly like configuration before it is stored, needs `manage_stores` (step-up verification by default) or `manage_definitions`, and is audited. A definition from code or configuration cannot be overridden or deleted here, and a persisted store may not change the layers the configuration file gives a store: moving data between drivers is [promote](commands.md)'s job.

## Loading

Persisted entries are read on first use, never at boot, once per request or job. An entry that fails validation (a layer that no longer exists, a driver that is not registered) is logged to the operational log and skipped, so a bad row never stops the application from booting; `doctor` lists what was skipped.

`configuration.sources` decides precedence: with `['config', 'database', 'runtime']` a persisted entry overrides the file, and a change made in code for one request ([runtime configuration](runtime-configuration.md)) overrides both. Put `database` first to let the file win.

---

[← Docs index](../../README.md#documentation)
