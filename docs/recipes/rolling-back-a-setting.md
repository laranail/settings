# Roll back a setting

Return a setting to an earlier value with `rollback()`, after looking at its history and comparing two versions.

## From PHP

```php
$settings = settings()->as($admin)->because('INC-42: wrong sender');

// 1. Find the version to return to.
foreach ($settings->history()->key('mail.from_address')->get(10) as $entry) {
    echo "{$entry->version} {$entry->operation->value} by {$entry->causerType->value} at {$entry->createdAt}\n";
}

// 2. Check what it held.
$diff = $settings->historyDiff('mail.from_address', 4, 6);

// 3. Roll back: written as a new version, audited, announced with SettingRolledBack.
$version = $settings->rollback('mail.from_address', 4);
```

The actor needs `view_history` for steps 1 and 2 and `rollback` for step 3. `rollback` needs step-up verification by default (`verification.abilities`): confirm the password first, or use the `actor` verifier with a fresh `verifiedAt`.

## From the console

```bash
php artisan laranail::settings.history mail.from_address
php artisan laranail::settings.history mail.from_address --diff=4..6
php artisan laranail::settings.history mail.from_address --rollback=4
```

Add `--owner=user:42`, `--store=` or `--locale=` for another scope. The command runs as the system actor and is audited under its name. An encrypted value prints as `[protected]` unless `--reveal` is given.

A locked setting refuses the rollback like any other write; unlock it first, or roll back as an actor holding `override_lock` on a store whose locks are overridable.

See [History](../tools/history.md).

---

[← Docs index](../../README.md#documentation)
