<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Actions\RescopeOwners;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Actions\ReencryptSettings;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Reencrypt reads every secret with the current or a previous key and writes it
 * under the current one (values, history, change requests); rescope renames an owner alias
 * everywhere it is stored; doctor reports what neither has fixed yet.
 */

beforeEach(function (): void {
    useDatabase(['stores.settings.owners' => ['user', 'member']]);
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
});

/** Switch APP_KEY, keeping the old one readable only when asked. */
function rotateKey(bool $keepPrevious): void
{
    $old = config('app.key');
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', $keepPrevious ? [$old] : []);

    foreach (['encrypter', LaravelValueEncrypter::class, ValuePipeline::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    app()->forgetScopedInstances();
}

it('re-encrypts values, history and change requests under the new key', function (): void {
    $secret = TestCredentials::secret();
    settings()->set('mail.password', $secret);
    settings()->set('mail.password', $secret . 'x');
    app(DefinitionRegistry::class)->define('mail.token')->encrypted()->requiresApproval()->register();
    settings()->set('mail.token', $secret);
    $before = DB::table('settings')->where('key', 'mail.password')->value('value');

    rotateKey(keepPrevious: true);
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();

    $exit = Artisan::call('laranail::settings.reencrypt');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Re-encrypted 1 value(s), 2 history entries and 1 change request(s)')
        ->and(DB::table('settings')->where('key', 'mail.password')->value('value'))->not->toBe($before)
        ->and(DB::table('settings_audit')->where('action', AuditAction::Reencrypt->value)->value('outcome'))->toBe('allowed');

    // The old key can go now: everything reads under the new one alone.
    config()->set('app.previous_keys', []);
    app()->forgetInstance('encrypter');
    app()->forgetInstance(LaravelValueEncrypter::class);
    app()->forgetInstance(ValuePipeline::class);
    app()->forgetScopedInstances();
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();

    expect(settings()->get('mail.password'))->toBe($secret . 'x')
        ->and(settings()->historyDiff('mail.password', 1, 2)->from)->toBe($secret);
});

it('leaves a value no key can read as it was, and says so', function (): void {
    settings()->set('mail.password', TestCredentials::secret());
    $before = DB::table('settings')->where('key', 'mail.password')->value('value');

    rotateKey(keepPrevious: false);

    expect(Artisan::call('laranail::settings.reencrypt'))->toBe(1)
        ->and(Artisan::output())->toContain('could not be decrypted')
        ->and(DB::table('settings')->where('key', 'mail.password')->value('value'))->toBe($before)
        ->and(Artisan::call('laranail::settings.doctor', ['--json' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('cannot be decrypted');
});

it('needs manage_stores to re-encrypt or rescope', function (): void {
    expect(fn () => app(ReencryptSettings::class)->handle(Actor::user(user())))->toThrow(AccessDenied::class, 'manage_stores')
        ->and(fn () => app(RescopeOwners::class)->handle('user', 'member', Actor::user(user())))->toThrow(AccessDenied::class, 'manage_stores');
});

it('renames an owner alias in values, history and change requests, and doctor stops flagging it', function (): void {
    useDatabase(['stores.settings.owners' => ['user', 'member']]);
    settings()->for(new OwnerRef('user', 4))->set('theme', 'dark');
    settings()->for(new OwnerRef('user', 4))->propose('theme', 'light');
    DB::table('settings')->insert(['id' => (string) Str::ulid(), 'uid' => (string) Str::ulid(), 'scope' => 'App\\Models\\Legacy:9', 'settable_type' => 'App\\Models\\Legacy', 'settable_id' => '9', 'key' => 'theme', 'group' => 'theme', 'locale' => '', 'value' => '"blue"', 'created_at' => now(), 'updated_at' => now()]);

    expect(Artisan::call('laranail::settings.rescope', ['from' => 'user', 'to' => 'member']))->toBe(0)
        ->and(Artisan::output())->toContain('in 1 value(s), 1 history entry and 1 change request(s)')
        ->and(settings()->for(new OwnerRef('member', 4))->get('theme'))->toBe('dark')
        ->and(DB::table('settings')->where('scope', 'member:4')->value('settable_type'))->toBe('member')
        ->and(DB::table('settings_history')->where('scope', 'member:4')->count())->toBe(1)
        ->and(DB::table('settings_change_requests')->where('scope', 'member:4')->count())->toBe(1);

    // A class name stored before the alias allow-list is renamed the same way.
    app(RescopeOwners::class)->handle('App\\Models\\Legacy', 'member');
    Artisan::call('laranail::settings.doctor', ['--json' => true]);

    expect(settings()->for(new OwnerRef('member', 9))->get('theme'))->toBe('blue')
        ->and(Artisan::output())->toContain('Every scope uses a listed alias.')
        ->and(fn () => app(RescopeOwners::class)->handle('member', 'member'))->toThrow(InvalidIdentifier::class, 'already')
        ->and(fn () => app(RescopeOwners::class)->handle('a:b', 'member'))->toThrow(InvalidIdentifier::class)
        ->and(Artisan::call('laranail::settings.rescope', ['from' => 'user', 'to' => 'Bad Alias']))->toBe(1);
});

it('reports overdue change requests and missing columns on the governance tables', function (): void {
    app(DefinitionRegistry::class)->define('mail.host')->requiresApproval()->register();
    settings()->set('mail.host', 'a.test');
    $this->travel(8)->days();

    Artisan::call('laranail::settings.doctor', ['--json' => true]);
    $report = Artisan::output();

    DB::connection()->getSchemaBuilder()->table('settings_change_requests', static function ($table): void {
        $table->dropColumn('decision_reason');
    });
    Artisan::call('laranail::settings.doctor', ['--json' => true]);

    expect($report)->toContain('past their expiry')
        ->and($report)->toContain('Every column is present.')
        ->and(Artisan::output())->toContain('settings_change_requests.decision_reason');
});
