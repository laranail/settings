<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\Precedence;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\UndecryptableValue;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Exceptions\EncryptionUnavailable;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
}

function definitions(): DefinitionRegistry
{
    return app(DefinitionRegistry::class);
}

it('casts values by the definition type', function (): void {
    definitions()->define('limits.seats')->ofType('int')->register();
    definitions()->define('features.beta')->ofType('bool')->register();
    definitions()->define('billing.renews_at')->ofType('datetime')->register();

    settings()->set('limits.seats', '12');
    settings()->set('features.beta', 'true');
    settings()->set('billing.renews_at', '2026-12-01T00:00:00+00:00');

    expect(settings()->get('limits.seats'))->toBe(12)
        ->and(settings()->get('features.beta'))->toBeTrue()
        ->and(settings()->get('billing.renews_at'))->toBeInstanceOf(CarbonImmutable::class);
});

it('casts enums registered by alias, never by class name', function (): void {
    app(CasterRegistry::class)->registerEnum('plan', Plan::class);
    definitions()->define('billing.plan')->ofType('enum:plan')->register();

    settings()->set('billing.plan', Plan::Pro);

    expect(settings()->get('billing.plan'))->toBe(Plan::Pro)
        ->and(fn () => settings()->set('billing.plan', 'gold'))->toThrow(InvalidSettingValue::class);
});

it('validates the raw value and names the key and rule, never the value', function (): void {
    definitions()->define('mail.from.address')->rules(['required', 'email'])->register();

    expect(fn () => settings()->set('mail.from.address', 'not-an-email'))
        ->toThrow(InvalidSettingValue::class, 'Setting [mail.from.address] failed validation: email.');
});

it('stores encrypted settings as ciphertext and reads them back', function (): void {
    definitions()->define('mail.password')->encrypted()->register();

    $secret = TestCredentials::secret();
    settings()->set('mail.password', $secret);

    expect(Storage::disk('local')->get('laranail/settings/settings.json'))->not->toContain($secret)
        ->and(settings()->get('mail.password'))->toBe($secret);
});

it('refuses to encrypt before an application key exists', function (): void {
    config()->set('app.key', '');
    definitions()->define('mail.password')->encrypted()->register();

    expect(fn () => settings()->set('mail.password', TestCredentials::secret()))->toThrow(EncryptionUnavailable::class);
});

it('reports an undecryptable value instead of returning garbage', function (): void {
    definitions()->define('mail.password')->encrypted()->register();
    settings()->set('mail.password', TestCredentials::secret());

    $file = 'laranail/settings/settings.json';
    $json = json_decode((string) Storage::disk('local')->get($file), true);
    $json['records'][0]['payload'] = 'not-ciphertext';
    Storage::disk('local')->put($file, json_encode($json));

    expect(fn (): mixed => settings()->get('mail.password'))->toThrow(UndecryptableValue::class);
});

it('rejects a definition that is both encrypted and public', function (): void {
    expect(fn (): Definition => definitions()->define('mail.password')->encrypted()->public()->register())
        ->toThrow(InvalidDefinition::class, 'cannot be both encrypted and public');
});

it('rejects a definition with an unknown type', function (): void {
    expect(fn (): Definition => definitions()->define('site.name')->ofType('money')->register())
        ->toThrow(InvalidDefinition::class, 'unknown type [money]');
});

it('returns the definition default when nothing is stored', function (): void {
    definitions()->define('site.name')->withDefault('Acme')->register();

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->lookup('site.name')->layer())->toBe('default');
});

it('makes unknown keys an error on reads and writes in a strict store', function (): void {
    config()->set('laranail.settings.stores.settings.strict', true);

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(UnknownSetting::class)
        ->and(fn () => settings()->set('site.name', 'Acme'))->toThrow(UnknownSetting::class);

    definitions()->define('site.name')->register();
    settings()->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('loads definitions from the config map', function (): void {
    config()->set('laranail.settings.definitions', [
        'limits.seats' => ['type' => 'int', 'default' => 3, 'rules' => ['integer', 'min:1'], 'label' => 'Seats'],
    ]);

    expect(settings()->get('limits.seats'))->toBe(3)
        ->and(fn () => settings()->set('limits.seats', 0))->toThrow(InvalidSettingValue::class, 'min');
});

it('refuses writes to a setting locked by its definition', function (): void {
    definitions()->define('site.name')->locked()->register();

    expect(fn () => settings()->set('site.name', 'Acme'))->toThrow(LockedSetting::class, 'locked by its definition');
});

it('locks and unlocks a single row', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->lock('site.name');

    expect(fn () => settings()->set('site.name', 'Other'))->toThrow(LockedSetting::class)
        ->and(fn () => settings()->forget('site.name'))->toThrow(LockedSetting::class)
        ->and(settings()->get('site.name'))->toBe('Acme');

    settings()->unlock('site.name');
    settings()->set('site.name', 'Other');

    expect(settings()->get('site.name'))->toBe('Other');
});

it('lets a shipped file win for a file-precedence setting and refuses writes to it', function (): void {
    shipDefaults(['compliance.retention_days' => 30]);
    definitions()->define('compliance.retention_days')->precedence(Precedence::File)->register();

    expect(settings()->get('compliance.retention_days'))->toBe(30)
        ->and(fn () => settings()->set('compliance.retention_days', 7))->toThrow(LockedSetting::class, 'cannot be written');
});

it('falls back through locales for a localised setting and refuses locales on others', function (): void {
    config()->set('app.fallback_locale', 'en');
    definitions()->define('site.greeting')->localised()->register();
    definitions()->define('site.name')->register();

    settings()->set('site.greeting', 'Hello');
    settings()->locale('fr')->set('site.greeting', 'Bonjour');

    expect(settings()->locale('fr')->get('site.greeting'))->toBe('Bonjour')
        ->and(settings()->locale('de')->get('site.greeting'))->toBe('Hello')
        ->and(fn () => settings()->locale('fr')->set('site.name', 'Acme'))->toThrow(InvalidSettingValue::class, 'not localised');
});
