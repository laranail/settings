<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Audit\LogAuditSink;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Logging\LogContext;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/** A logger keeping each entry's context. */
function contextLog(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array<array-key, mixed>> */
        public array $contexts = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->contexts[] = $context;
        }
    };
}

beforeEach(function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);
});

it('shows the resolved value, its layer, type, version, flags and the last audit entry', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');

    $this->artisan('laranail::settings.show', ['key' => 'site.name'])
        ->expectsOutputToContain('"Acme Ltd"')
        ->expectsOutputToContain('| Layer      | database')
        ->expectsOutputToContain('| From scope | global')
        ->expectsOutputToContain('| Version    | 2')
        ->expectsOutputToContain('| Locked     | no')
        ->expectsOutputToContain('| Encrypted  | no')
        ->expectsOutputToContain('| Last audit | update allowed by system via php')
        ->expectsOutputToContain('| Stored     | version 2')
        ->expectsOutputToContain('| Cache      | current')
        ->assertSuccessful();
});

it('reads past the cache and flags a stale one', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->get('site.name');
    // A change the cache never saw, as a hand edit or another deploy's write would be.
    DB::table('settings')->where('key', 'site.name')->update(['version' => 5]);

    $this->artisan('laranail::settings.show', ['key' => 'site.name'])
        ->expectsOutputToContain('| Version    | 1')
        ->expectsOutputToContain('| Stored     | version 5')
        ->expectsOutputToContain('| Cache      | stale: run laranail::settings.clear-cache')
        ->assertSuccessful();
});

it('shows an owner value and reports a missing one', function (): void {
    settings()->for(new OwnerRef('user', 7))->set('theme', 'dark');

    $this->artisan('laranail::settings.show', ['key' => 'theme', '--owner' => 'user:7'])
        ->expectsOutputToContain('"dark"')
        ->expectsOutputToContain('| Scope      | user:7')
        ->assertSuccessful();

    $this->artisan('laranail::settings.show', ['key' => 'theme'])
        ->expectsOutputToContain('(not set)')
        ->expectsOutputToContain('| Version    | -')
        ->assertSuccessful();

    $this->artisan('laranail::settings.show', ['key' => 'theme', '--owner' => 'nonsense'])
        ->expectsOutputToContain('is not alias:id')
        ->assertFailed();
});

it('masks a sensitive value unless --reveal is given', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    $secret = TestCredentials::secret();
    settings()->set('mail.password', $secret);

    $this->artisan('laranail::settings.show', ['key' => 'mail.password'])
        ->expectsOutputToContain('[protected]')
        ->doesntExpectOutputToContain($secret)
        ->expectsOutputToContain('| Encrypted  | yes')
        ->expectsOutputToContain('| Sensitive  | yes')
        ->assertSuccessful();

    $this->artisan('laranail::settings.show', ['key' => 'mail.password', '--reveal' => true])
        ->expectsOutputToContain('"' . $secret . '"')
        ->assertSuccessful();
});

it('warns, without failing, about a value whose key is outside its category', function (): void {
    settings()->categories()->create(new NewCategory('mail', 'Mail'));
    $mail = DB::table('settings_categories')->where('slug', 'mail')->value('id');
    $now = now();
    $row = static fn (string $key, string $uid): array => ['id' => (string) Str::ulid(), 'scope' => 'global', 'key' => $key, 'locale' => '', 'group' => 'x', 'uid' => $uid, 'value' => '1', 'version' => 1, 'category_id' => $mail, 'category_store' => 'settings', 'created_at' => $now, 'updated_at' => $now];

    DB::table('settings')->insert($row('mail.host', str_repeat('A', 26)));

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain("Every categorised key starts with its category's slug.")
        ->assertSuccessful();

    DB::table('settings')->insert($row('site.name', str_repeat('B', 26)));

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain("1 key(s) do not start with their category's slug: site.name (mail)")
        ->assertSuccessful();
});

it('warns, without failing, about a definition whose key is outside its category', function (): void {
    app(DefinitionRegistry::class)->define('mail.host')->inCategory('mail')->register();

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain("Every categorised definition starts with its category's slug.")
        ->assertSuccessful();

    app(DefinitionRegistry::class)->define('site.name')->inCategory('mail')->register();

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain("1 definition(s) do not start with their category's slug: settings:site.name (mail)")
        ->assertSuccessful();
});

it('puts store, key, scope, actor and channel first in every log context', function (): void {
    $sink = contextLog();
    $logger = new OperationalLogger($sink, 'debug');

    $logger->info('plain', ['extra' => 1]);
    $logger->info('built', LogContext::of('settings', 'site.name', 'global', Actor::system('nightly', Channel::Console), extra: ['extra' => 2]));

    expect($sink->contexts[0])->toBe(['store' => null, 'key' => null, 'scope' => null, 'actor' => null, 'channel' => null, 'extra' => 1])
        ->and(array_keys($sink->contexts[1]))->toBe(['store', 'key', 'scope', 'actor', 'channel', 'extra'])
        ->and($sink->contexts[1]['channel'])->toBe('console')
        ->and($sink->contexts[1]['actor'])->toBe(['type' => 'system', 'alias' => null, 'id' => null, 'channel' => 'console']);
});

it('logs an audit entry with the standard context', function (): void {
    $sink = contextLog();
    new LogAuditSink(new OperationalLogger($sink, 'debug'))->record(new AuditEntry(
        '01JAAAAAAAAAAAAAAAAAAAAAAA',
        now()->toImmutable(),
        AuditAction::Update,
        AuditOutcome::Allowed,
        'settings',
        'user:7',
        'theme',
        '',
        null,
        2,
        ActorType::User,
        'user',
        '7',
        Channel::Rest,
        null,
        null,
        null,
        null,
    ));

    expect(array_slice($sink->contexts[0], 0, 5))->toBe([
        'store'   => 'settings',
        'key'     => 'theme',
        'scope'   => 'user:7',
        'actor'   => ['type' => 'user', 'alias' => 'user', 'id' => '7'],
        'channel' => 'rest',
    ])->and($sink->contexts[0]['action'])->toBe('update');
});

it('logs a refusal with the standard context', function (): void {
    $sink = contextLog();
    config()->set('laranail.settings.authorization.driver', 'permissions');
    app()->forgetScopedInstances();
    settings()->configuration()->logTo($sink);

    try {
        settings()->as(user(3))->set('site.name', 'Nope');
    } catch (Throwable) {
    }

    $refusal = array_values(array_filter($sink->contexts, static fn (array $context): bool => array_key_exists('reason', $context)))[0] ?? [];

    expect(array_slice($refusal, 0, 3))->toBe(['store' => 'settings', 'key' => 'site.name', 'scope' => 'global'])
        ->and($refusal['actor']['id'] ?? null)->toBe('3')
        ->and($refusal['channel'])->toBe('php');
});
