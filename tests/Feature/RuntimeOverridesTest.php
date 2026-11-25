<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Simtabi\Laranail\Settings\Events\SettingSaving;
use Simtabi\Laranail\Settings\Exceptions\InvalidKey;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Services\Resolution\Overrides;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Runtime overrides: request-, job- or scope-bound values held in memory, answering ahead of every
 * stored layer along the same owner, parents and global walk. Nothing is written, audited or
 * cached, and forgetScopedInstances() (what Octane and the queue worker run between lifecycles)
 * drops them.
 */

beforeEach(function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user', 'team']);
});

final readonly class OverrideMember implements Settable
{
    public function __construct(private int $id, private ?int $teamId) {}

    public function settingsOwner(): OwnerRef
    {
        return new OwnerRef('user', $this->id);
    }

    public function settingsParents(): array
    {
        return OwnerRef::list(['team' => $this->teamId]);
    }
}

describe('precedence', function (): void {
    it('answers ahead of every stored layer and the definition default, and forgetting it restores them', function (): void {
        shipDefaults(['mail.from' => 'shipped@example.test', 'site.name' => 'Shipped']);
        app(DefinitionRegistry::class)->define('ui.theme')->withDefault('light')->register();
        settings()->set('mail.from', 'stored@example.test');

        settings()->override(['mail.from' => 'override@example.test', 'site.name' => 'Override', 'ui.theme' => 'dark']);

        expect(settings()->get('mail.from'))->toBe('override@example.test')
            ->and(settings()->get('site.name'))->toBe('Override')
            ->and(settings()->get('ui.theme'))->toBe('dark')
            ->and(settings()->lookup('mail.from')->layer())->toBe(Overrides::LAYER);

        settings()->forgetOverride('mail.from');
        settings()->forgetOverride('site.name');
        settings()->forgetOverride('ui.theme');

        expect(settings()->get('mail.from'))->toBe('stored@example.test')
            ->and(settings()->lookup('mail.from')->layer())->toBe('runtime')
            ->and(settings()->get('site.name'))->toBe('Shipped')
            ->and(settings()->get('ui.theme'))->toBe('light');
    });

    it('treats a null override as an answer, ahead of a stored value', function (): void {
        settings()->set('mail.from', 'stored@example.test');
        settings()->override('mail.from');

        expect(settings()->has('mail.from'))->toBeTrue()
            ->and(settings()->get('mail.from', 'default'))->toBeNull();
    });

    it('applies a global override to every owner, unless the owner has its own', function (): void {
        app(DefinitionRegistry::class)->define('ui.theme')->inherits()->register();
        $alice = new OverrideMember(1, teamId: 7);
        $bob = new OverrideMember(2, teamId: 7);
        settings()->for($alice)->set('ui.theme', 'stored-for-alice');

        settings()->override('ui.theme', 'global-override');

        expect(settings()->for($alice)->get('ui.theme'))->toBe('global-override')
            ->and(settings()->for($bob)->get('ui.theme'))->toBe('global-override');

        settings()->for($bob)->override('ui.theme', 'bob-override');

        expect(settings()->for($bob)->get('ui.theme'))->toBe('bob-override')
            ->and(settings()->for($alice)->get('ui.theme'))->toBe('global-override')
            ->and(settings()->get('ui.theme'))->toBe('global-override');
    });

    it('walks the parents: a team override reaches its members ahead of the global one', function (): void {
        app(DefinitionRegistry::class)->define('ui.density')->inherits()->register();
        settings()->override('ui.density', 'global');
        settings()->for(new OwnerRef('team', 7))->override('ui.density', 'team');

        expect(settings()->for(new OverrideMember(1, teamId: 7))->get('ui.density'))->toBe('team')
            ->and(settings()->for(new OverrideMember(2, teamId: 8))->get('ui.density'))->toBe('global')
            ->and(settings()->for(new OverrideMember(1, teamId: 7))->lookup('ui.density')->scope()?->value)->toBe('team:7');
    });

    it('keeps a global override from an owner whose definition does not inherit', function (): void {
        app(DefinitionRegistry::class)->define('mail.signature')->withDefault('none')->register();
        settings()->override('mail.signature', 'global');

        expect(settings()->for(new OverrideMember(1, teamId: null))->get('mail.signature'))->toBe('none');
    });

    it('keys an override by locale, and falls back as a stored value does', function (): void {
        config()->set('app.fallback_locale', 'en');
        app()->forgetScopedInstances();
        app(DefinitionRegistry::class)->define('site.tagline')->localised()->register();
        app(DefinitionRegistry::class)->define('site.name')->register();
        settings()->locale('fr')->set('site.tagline', 'Bonjour (stored)');

        settings()->locale('fr')->override('site.tagline', 'Bonjour');
        settings()->locale('en')->override('site.tagline', 'Hello');
        settings()->locale('fr')->override('site.name', 'Acme');

        expect(settings()->locale('fr')->get('site.tagline'))->toBe('Bonjour')
            ->and(settings()->locale('de')->get('site.tagline'))->toBe('Hello')
            ->and(settings()->locale('fr')->hasOverride('site.tagline'))->toBeTrue()
            ->and(settings()->locale('de')->hasOverride('site.tagline'))->toBeFalse()
            // A key whose definition is not localised holds one override, whatever the locale.
            ->and(settings()->get('site.name'))->toBe('Acme');
    });

    it('wins over a locked setting, which a write could not change', function (): void {
        settings()->set('billing.plan', 'free');
        settings()->lock('billing.plan');

        settings()->override('billing.plan', 'pro');

        expect(settings()->get('billing.plan'))->toBe('pro');

        settings()->clearOverrides();

        expect(settings()->get('billing.plan'))->toBe('free');
    });
});

describe('values', function (): void {
    it('casts an override as a write is cast, so typed getters return the right type', function (): void {
        app(DefinitionRegistry::class)->define('limits.seats')->ofType('int')->register();
        app(DefinitionRegistry::class)->define('features.beta')->ofType('bool')->register();

        settings()->override(['limits.seats' => '12', 'features.beta' => 'true']);

        expect(settings()->integer('limits.seats'))->toBe(12)
            ->and(settings()->boolean('features.beta'))->toBeTrue()
            ->and(fn (): string => settings()->string('limits.seats'))->toThrow(TypeMismatch::class);
    });

    it('refuses a value its definition refuses, and one over values.max_bytes', function (): void {
        app(DefinitionRegistry::class)->define('limits.seats')->ofType('int')->register();
        app(DefinitionRegistry::class)->define('mail.from')->rules(['email'])->register();

        expect(fn () => settings()->override('limits.seats', 'many'))->toThrow(InvalidSettingValue::class, 'expects an integer')
            ->and(fn () => settings()->override('mail.from', 'not-an-email'))->toThrow(InvalidSettingValue::class, 'failed validation: email');

        config()->set('laranail.settings.values.max_bytes', 16);
        app()->forgetScopedInstances();
        app()->forgetInstance(ValuePipeline::class);

        expect(fn () => settings()->override('site.name', str_repeat('x', 64)))->toThrow(InvalidSettingValue::class, 'values.max_bytes')
            ->and(settings()->hasOverride('limits.seats'))->toBeFalse()
            ->and(settings()->hasOverride('site.name'))->toBeFalse();
    });

    it('refuses a list entry with no key, and an undefined key in a strict store', function (): void {
        expect(fn () => settings()->override(['site.name']))->toThrow(InvalidKey::class, 'override()');

        config()->set('laranail.settings.stores.settings.strict', true);
        app()->forgetScopedInstances();

        expect(fn () => settings()->override('nowhere.defined', 1))->toThrow(UnknownSetting::class);
    });
});

describe('withOverrides', function (): void {
    it('holds the overrides while the callback runs and returns its result', function (): void {
        settings()->set('mail.from', 'stored@example.test');

        $seen = settings()->withOverrides(['mail.from' => 'inside@example.test'], static fn (): mixed => settings()->get('mail.from'));

        expect($seen)->toBe('inside@example.test')
            ->and(settings()->get('mail.from'))->toBe('stored@example.test')
            ->and(settings()->hasOverride('mail.from'))->toBeFalse();
    });

    it('restores the previous overrides when the callback throws', function (): void {
        settings()->override('mail.from', 'outer@example.test');

        expect(fn (): mixed => settings()->withOverrides(['mail.from' => 'inner@example.test', 'site.name' => 'Inner'], static function (): never {
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class, 'boom');

        expect(settings()->get('mail.from'))->toBe('outer@example.test')
            ->and(settings()->hasOverride('site.name'))->toBeFalse();
    });

    it('nests, each level restoring the one around it', function (): void {
        $trail = settings()->withOverrides(['site.name' => 'Outer'], static function (): array {
            $inner = settings()->withOverrides(['site.name' => 'Inner', 'mail.from' => 'inner@example.test'], static fn (): array => [settings()->get('site.name'), settings()->get('mail.from')]);

            return [...$inner, settings()->get('site.name'), settings()->hasOverride('mail.from')];
        });

        expect($trail)->toBe(['Inner', 'inner@example.test', 'Outer', false])
            ->and(settings()->hasOverride('site.name'))->toBeFalse();
    });

    it('also drops an override the callback set itself', function (): void {
        settings()->withOverrides([], static function (): void {
            settings()->override('site.name', 'Set inside');
        });

        expect(settings()->hasOverride('site.name'))->toBeFalse();
    });
});

describe('nothing durable', function (): void {
    it('writes, audits and dispatches nothing', function (): void {
        $sink = auditInMemory();
        $configured = count($sink->entries());
        Event::fake([SettingSaving::class, SettingSaved::class]);

        settings()->override('site.name', 'Override');
        settings()->forgetOverride('site.name');
        settings()->withOverrides(['site.name' => 'Inner'], static fn (): mixed => settings()->get('site.name'));
        settings()->clearOverrides();

        expect($sink->entries())->toHaveCount($configured)
            ->and(settings()->has('site.name'))->toBeFalse();
        Event::assertNotDispatched(SettingSaving::class);
        Event::assertNotDispatched(SettingSaved::class);
    });

    it('needs no write ability, while reading still needs view and view_sensitive', function (): void {
        app(DefinitionRegistry::class)->define('mail.api_key')->sensitive()->register();
        settings()->override('mail.api_key', TestCredentials::secret());
        allowGates(Ability::View);

        // Overriding is a code-level call: an actor without update still holds an override.
        settings()->as(user())->override('site.name', 'Acme');

        expect(settings()->as(user())->get('site.name'))->toBe('Acme')
            ->and(fn (): Lookup => settings()->as(user())->lookup('mail.api_key'))->toThrow(AccessDenied::class, '[view_sensitive]')
            ->and(settings()->as(user())->explain('mail.api_key')->isRedacted())->toBeTrue();
    });

    it('ends with the request or job: a flush of scoped instances drops every override', function (): void {
        settings()->set('mail.from', 'stored@example.test');

        // Request one.
        settings()->override('mail.from', 'request-one@example.test');
        expect(settings()->get('mail.from'))->toBe('request-one@example.test');

        // What Octane and the queue worker run between lifecycles.
        app()->forgetScopedInstances();

        // Request two, in the same container.
        expect(settings()->get('mail.from'))->toBe('stored@example.test')
            ->and(settings()->hasOverride('mail.from'))->toBeFalse()
            ->and(app(Overrides::class)->isEmpty())->toBeTrue();
    });

    it('is shared by every handle of the request, and clearOverrides() drops them all', function (): void {
        settings()->override('site.name', 'Global');
        settings()->for(new OwnerRef('user', 1))->override('site.name', 'User');

        expect(app(Simtabi\Laranail\Settings\Contracts\Settings::class)->for(new OwnerRef('user', 1))->hasOverride('site.name'))->toBeTrue();

        settings()->for(new OwnerRef('user', 9))->clearOverrides();

        expect(settings()->hasOverride('site.name'))->toBeFalse()
            ->and(settings()->for(new OwnerRef('user', 1))->hasOverride('site.name'))->toBeFalse();
    });
});

describe('visibility', function (): void {
    it('lists overridden keys in all()', function (): void {
        settings()->set('mail.from', 'stored@example.test');
        settings()->override('site.name', 'Override');

        expect(settings()->all())->toBe(['mail.from' => 'stored@example.test', 'site.name' => 'Override']);
    });

    it('reports the override as the source in explain(), and leaves the steps alone without one', function (): void {
        settings()->set('site.name', 'Stored');
        $before = settings()->explain('site.name');

        settings()->override('site.name', 'Override');
        $explained = settings()->explain('site.name');

        expect($before->steps)->each->not->toContain(Overrides::LAYER)
            ->and($explained->lookup->layer())->toBe('override')
            ->and($explained->lookup->scope()?->value)->toBe('global')
            ->and($explained->lookup->value())->toBe('Override')
            ->and($explained->steps)->toBe(['settings / global / (none) / override']);
    });
});

describe('the fake', function (): void {
    it('answers with an override and never records it as saved', function (): void {
        $fake = Settings::fake();
        Settings::set('site.name', 'Stored');

        Settings::override('site.name', 'Override');

        expect(Settings::get('site.name'))->toBe('Override')
            ->and(Settings::hasOverride('site.name'))->toBeTrue()
            ->and(Settings::withOverrides(['site.name' => 'Inner'], static fn (): mixed => Settings::get('site.name')))->toBe('Inner');

        $fake->assertSaved('site.name', 'Stored');
        $fake->assertNotSaved('mail.from');
        Settings::override('mail.from', 'override@example.test');
        $fake->assertNotSaved('mail.from');

        Settings::clearOverrides();

        expect(Settings::get('site.name'))->toBe('Stored');
    });

    it('drops the fake\'s overrides with the request, like the engine\'s', function (): void {
        Settings::fake();
        Settings::override('site.name', 'Override');
        app()->forgetScopedInstances();

        expect(app(Overrides::class)->isEmpty())->toBeTrue();
    });
});

describe('the REST API', function (): void {
    it('exposes no override route and calls no override method', function (): void {
        $this->rebootWith(['laranail.settings.http.enabled' => true]);
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn (Illuminate\Routing\Route $route): bool => str_starts_with((string) $route->getName(), 'laranail-settings.api.'));
        $offending = array_filter($routes, static fn (Illuminate\Routing\Route $route): bool => str_contains(strtolower($route->uri() . ' ' . $route->getName()), 'override') && ! str_contains($route->uri(), 'override-lock'));
        $sources = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Http', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        $calls = array_keys(array_filter($sources, static fn (string $code): bool => preg_match('/->(override|forgetOverride|hasOverride|clearOverrides|withOverrides)\(/', $code) === 1));

        expect(count($routes))->toBeGreaterThanOrEqual(39)
            ->and(count($sources))->toBeGreaterThanOrEqual(20)
            ->and($offending)->toBe([])
            ->and($calls)->toBe([]);
    });
});
