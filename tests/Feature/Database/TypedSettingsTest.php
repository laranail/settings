<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Typed\TypedProperty;
use Simtabi\Laranail\Settings\Typed\TypedSettings;
use Simtabi\Laranail\Settings\Typed\Attributes\Type;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Typed\SettingsMigration;
use Simtabi\Laranail\Settings\Tests\Fixtures\Typed\Plan;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Tests\Fixtures\Typed\MailSettings;
use Simtabi\Laranail\Settings\Tests\Fixtures\Typed\BrokenSettings;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Phase 5: typed settings classes over the key-value engine. The typed API and the key-value
 * API read and write the same rows (parity), saves go through the write actions (actor, audit,
 * history), and settings migrations rename, move, add and delete keys (G-17).
 */

beforeEach(function (): void {
    useDatabase(['stores.settings.owners' => ['user'], 'typed.classes' => [MailSettings::class]]);
});

it('maps properties to keys and loads defaults until values are stored', function (): void {
    $mail = MailSettings::load(settings());

    expect(array_map(static fn (TypedProperty $property): string => $property->key, MailSettings::properties()))->toBe([
        'fromAddress' => 'mail.from_address',
        'port'        => 'mail.port',
        'timeout'     => 'mail.timeout',
        'secure'      => 'mail.secure',
        'cc'          => 'mail.cc',
        'plan'        => 'mail.plan',
        'launchedAt'  => 'mail.launched_at',
        'password'    => 'mail.password',
        'siteName'    => 'site.name',
    ])
        ->and($mail->fromAddress)->toBe('hello@example.test')
        ->and($mail->plan)->toBe(Plan::Free)
        // typed.classes registered the defaults as definitions: the key-value API sees them too.
        ->and(settings()->get('mail.port'))->toBe(25);
});

it('writes through the key-value engine, so both APIs hold the same rows (parity)', function (): void {
    $secret = TestCredentials::secret();
    $mail = MailSettings::load(settings());
    $mail->fromAddress = 'ops@acme.test';
    $mail->port = 587;
    $mail->timeout = 2.5;
    $mail->secure = true;
    $mail->cc = ['a@acme.test'];
    $mail->plan = Plan::Pro;
    $mail->launchedAt = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
    $mail->password = $secret;
    $mail->save();
    $typedRows = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();

    expect(settings()->get('mail.from_address'))->toBe('ops@acme.test')
        ->and(settings()->get('mail.plan'))->toBe('pro')
        ->and(settings()->get('mail.password'))->toBe($secret)
        ->and($typedRows['mail.password'])->not->toContain($secret)
        ->and(array_keys($typedRows))->not->toContain('site.name');

    // The same values through the key-value API, into an empty database, store the same payloads.
    useDatabase(['stores.settings.owners' => ['user'], 'typed.classes' => [MailSettings::class]]);
    foreach (['mail.from_address' => 'ops@acme.test', 'mail.port' => 587, 'mail.timeout' => 2.5, 'mail.secure' => true, 'mail.cc' => ['a@acme.test'], 'mail.plan' => 'pro', 'mail.launched_at' => '2026-01-02T03:04:05+00:00', 'mail.password' => $secret] as $key => $value) {
        settings()->set($key, $value);
    }
    $kvRows = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();
    unset($typedRows['mail.password'], $kvRows['mail.password']);

    $loaded = MailSettings::load(settings());

    expect($kvRows)->toBe($typedRows)
        ->and($loaded->plan)->toBe(Plan::Pro)
        ->and($loaded->launchedAt?->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05')
        ->and($loaded->cc)->toBe(['a@acme.test'])
        ->and($loaded->password)->toBe($secret)
        ->and($loaded->timeout)->toBe(2.5);
});

it('saves only what changed, as the handle\'s actor, for an owner', function (): void {
    allowGates(Ability::View, Ability::ViewSensitive, Ability::Update);
    $owner = settings()->as(user(3))->for(new OwnerRef('user', 3));
    $mail = MailSettings::load($owner);
    $mail->port = 2525;
    $mail->save();

    expect(DB::table('settings')->where('scope', 'user:3')->pluck('key')->all())->toBe(['mail.port'])
        ->and(DB::table('settings_audit')->where('action', 'update')->where('actor_id', '3')->count())->toBe(1)
        ->and(app(MailSettings::class)->port)->toBe(25)
        ->and(MailSettings::load(settings()->for(new OwnerRef('user', 3)))->port)->toBe(2525);

    Gate::define(Ability::Update->gateName(), static fn (): bool => false);
    $mail->port = 1;

    expect(fn () => $mail->save())->toThrow(AccessDenied::class);
});

it('refuses a stored value its property cannot hold, and a type it cannot map', function (): void {
    useDatabase(['stores.settings.owners' => ['user'], 'typed.classes' => []]);
    app()->forgetInstance(DefinitionRegistry::class);
    settings()->set('mail.port', 'not a number');

    expect(fn (): MailSettings => MailSettings::load(settings()))->toThrow(TypeMismatch::class, 'mail.port')
        ->and(fn (): array => BrokenSettings::properties())->toThrow(InvalidDefinition::class, 'stdClass')
        ->and(fn () => new MailSettings()->save())->toThrow(TypeMismatch::class, 'was not loaded');
});

it('copies every property to another handle when one is given', function (): void {
    $mail = MailSettings::load(settings());
    $mail->save(settings()->for(new OwnerRef('user', 8)));

    expect(DB::table('settings')->where('scope', 'user:8')->count())->toBe(9);
});

it('renames, moves, adds and deletes keys in a settings migration', function (): void {
    useDatabase(['stores.settings.owners' => ['user'], 'stores.archive' => ['layers' => ['database'], 'owners' => ['team']]], stores: ['archive']);
    settings()->set('mail.from', 'a@acme.test');
    settings()->for(new OwnerRef('user', 1))->set('mail.from', 'b@acme.test');
    settings()->for(new OwnerRef('user', 1))->lock('mail.from');
    settings()->set('legacy.flag', true);

    $migration = new class extends SettingsMigration
    {
        public function up(): void
        {
            $this->settings()->rename('mail.from', 'mail.from_address');
            $this->settings()->move('legacy.flag', 'settings', 'archive');
            $this->settings()->add('site.name', 'Acme');
            $this->settings()->add('mail.from_address', 'ignored');
            $this->settings()->delete('nothing.here');
        }
    };
    $migration->up();

    expect(settings()->get('mail.from_address'))->toBe('a@acme.test')
        ->and(settings()->for(new OwnerRef('user', 1))->get('mail.from_address'))->toBe('b@acme.test')
        ->and((bool) DB::table('settings')->where('scope', 'user:1')->where('key', 'mail.from_address')->value('is_locked'))->toBeTrue()
        ->and(DB::table('settings')->where('key', 'mail.from')->count())->toBe(0)
        ->and(settings()->store('archive')->get('legacy.flag'))->toBeTrue()
        ->and(settings()->get('legacy.flag'))->toBeNull()
        ->and(settings()->get('site.name'))->toBe('Acme')
        ->and(DB::table('settings_audit')->where('action', 'migrate')->count())->toBe(5)
        ->and(settings()->history()->global()->key('mail.from_address')->get())->toHaveCount(1);
});

it('resolves a class listed in typed.classes from the container, loaded', function (): void {
    $this->rebootWith(['laranail.settings.typed.classes' => [MailSettings::class]]);
    useDatabase(['stores.settings.owners' => ['user'], 'typed.classes' => [MailSettings::class]]);
    settings()->set('mail.port', 465);

    expect(app(MailSettings::class)->port)->toBe(465)
        ->and(app(MailSettings::class))->toBeInstanceOf(TypedSettings::class)
        ->and(app()->bound(MailSettings::class))->toBeTrue();
});

it('binds only the classes typed.classes lists when the application boots', function (): void {
    expect(app()->bound(MailSettings::class))->toBeFalse();
});

it('refuses a typed.classes entry that is not a typed settings class', function (): void {
    config()->set('laranail.settings.typed.classes', [stdClass::class]);
    app()->forgetInstance(DefinitionRegistry::class);

    expect(fn () => app(DefinitionRegistry::class))->toThrow(InvalidConfiguration::class, 'typed.classes');
});

it('registers definitions with the caster alias a #[Type] names', function (): void {
    $class = new class extends TypedSettings
    {
        #[Type('string')]
        public string $region = 'eu';
    };
    $definitions = $class::register(app(DefinitionRegistry::class));

    expect($definitions[0]->type)->toBe('string')
        ->and($definitions[0]->default)->toBe('eu')
        ->and($class::register(app(DefinitionRegistry::class)))->toBe([]);
});
