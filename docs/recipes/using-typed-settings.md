# Use typed settings

Group related keys into a class with typed properties, so the rest of the application reads `$mail->port` instead of `get('mail.port')`.

## Declare the class

```php
use Simtabi\Laranail\Settings\Typed\TypedSettings;
use Simtabi\Laranail\Settings\Typed\Attributes\{Encrypted, Group};

#[Group('mail')]
final class MailSettings extends TypedSettings
{
    public string $fromAddress = 'hello@example.com';   // mail.from_address
    public string $fromName = 'Acme';                     // mail.from_name
    public int $port = 25;                                // mail.port
    #[Encrypted] public ?string $password = null;        // defined encrypted
}
```

## Register it

```php
// config/laranail/settings.php
'typed' => ['classes' => [App\Settings\MailSettings::class]],
```

Each property becomes a definition (type, default, encryption), so `get('mail.port')`, the REST API and the admin UI see the same types and defaults.

## Inject and read it

```php
final readonly class MailerFactory
{
    public function __construct(private MailSettings $mail) {}

    public function sender(): Address
    {
        return new Address($this->mail->fromAddress, $this->mail->fromName);
    }
}
```

The container builds it with `MailSettings::load()` through the `Settings` handle, so caching and the cascade apply.

## Change it as a user

```php
$mail = MailSettings::load($settings->as($request->user())->because('new SMTP relay'));
$mail->port = 587;
$mail->save();   // writes mail.port only, through set(): validated, audited, versioned
```

## Per owner

```php
$teamMail = MailSettings::load($settings->for($team));
```

## Evolve it with migrations

Renaming a property renames its key; move the stored values with a settings migration instead of losing them. See [Typed settings](../tools/typed-settings.md) for the attributes, the supported types and `SettingsMigration`.

---

[← Docs index](../../README.md#documentation)
