# Typed settings

Classes whose typed properties are settings, loaded and saved through the key-value engine. The idea comes from [spatie/laravel-settings](https://github.com/spatie/laravel-settings), credited with thanks; here a typed class is a view over keys, not a store of its own.

## A typed class

```php
use Simtabi\Laranail\Settings\Typed\TypedSettings;
use Simtabi\Laranail\Settings\Typed\Attributes\{Encrypted, Group, Key, Store, Type};

#[Store('settings')]          // optional: the handle's store otherwise
#[Group('mail')]              // the key prefix
final class MailSettings extends TypedSettings
{
    public string $fromAddress = 'hello@example.com';    // mail.from_address
    public int $port = 25;                                // mail.port
    public Plan $plan = Plan::Free;                       // a backed enum, stored as its value
    public ?DateTimeImmutable $launchedAt = null;         // stored as ISO 8601
    #[Encrypted] public ?string $password = null;         // defined encrypted by register()
    #[Key('site.name')] public string $siteName = 'Acme'; // an exact key
    #[Type('enum:region')] public string $region = 'eu';  // the caster alias register() uses
}
```

Supported property types: `string`, `int`, `float`, `bool`, `array`, a backed enum, and `DateTimeImmutable` / `CarbonImmutable`, each nullable. Another type is refused with `InvalidDefinition`.

## Loading and saving

```php
$mail = MailSettings::load($settings);                       // or app(MailSettings::class)
$mail->fromAddress;                                          // the stored value, else the default

$mail = MailSettings::load($settings->as($admin)->for($team));
$mail->port = 587;
$mail->save();                                               // writes mail.port only
$mail->save($settings->for($otherTeam));                     // writes every property there
```

`load()` reads each key through the handle, so the store, owner, locale and actor apply exactly as for `get()`, including the cascade and `view` / `view_sensitive`. `save()` writes each property that changed through `set()`: validation, casting, encryption, locks, approvals, history and audit all apply, as the handle's actor. A stored value the property cannot hold raises `TypeMismatch` naming the key.

Resolving a class listed in `typed.classes` from the container loads it through the request's settings handle; the package binds each listed class when the application boots. A class not listed is not bound: build it with `MailSettings::load(settings())`.

## Definitions

`MailSettings::register($registry)` registers a definition per property (its caster alias, its declared default, `#[Encrypted]`) in the class's store, so the key-value API reads the same values with the same types. Keys already defined in code or configuration are left alone. List classes in `typed.classes` to have them registered when definitions are first read:

```php
'typed' => ['classes' => [App\Settings\MailSettings::class]],
```

Register a class with an `#[Encrypted]` property before saving it, or the value is stored like any other.

## Settings migrations

Rename, move, add and delete keys in a migration, in order with the application's other migrations:

```php
use Simtabi\Laranail\Settings\Typed\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->settings()->rename('mail.from', 'mail.from_address');      // every owner and locale
        $this->settings()->move('legacy.flag', 'settings', 'archive');
        $this->settings()->add('site.name', 'Acme');                      // unless a global value exists
        $this->settings()->delete('mail.debug');
    }

    public function down(): void
    {
        $this->settings()->rename('mail.from_address', 'mail.from');
    }
};
```

Values move through the value pipeline (re-validated, re-cast and re-encrypted under the destination's definition) with their history, and locks move with them. A migration runs as the system actor, writes past approvals as trusted code does, and is audited once per step (`migrate`). On a database store every scope is migrated; on a file-only store, the global value.

---

[← Docs index](../../README.md#documentation)
