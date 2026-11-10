<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\HistoryDiff;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Actions\PruneHistory;
use Simtabi\Laranail\Settings\Events\HistoryPruned;
use Simtabi\Laranail\Settings\Enums\HistoryOperation;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Events\SettingRolledBack;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Exceptions\HistoryUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Every set, forget, lock, unlock and rollback appends the value before and after, as
 * stored, in the same transaction as the change; history is queried by (store, scope, key,
 * locale); a rollback is a write; a diff decrypts only for an actor allowed to see the value.
 */

beforeEach(function (): void {
    useDatabase();
});

/** @return list<string> operation:version, newest first */
function trail(string $key, ?OwnerRef $owner = null): array
{
    $history = $owner instanceof OwnerRef ? settings()->for($owner)->history() : settings()->history()->global();

    return array_map(static fn (HistoryEntry $entry): string => $entry->operation->value . ':' . $entry->version, $history->key($key)->get());
}

it('records every change with the payload before and after, the causer and the reason', function (): void {
    allowGates(Ability::Update, Ability::Lock, Ability::Unlock);

    settings()->set('site.name', 'Acme');
    settings()->as(user(7))->because('INC-9')->set('site.name', 'Acme Ltd');
    settings()->lock('site.name');
    settings()->unlock('site.name');

    $entries = settings()->history()->key('site.name')->get();
    $update = $entries[2];

    expect(trail('site.name'))->toBe(['unlock:4', 'lock:3', 'set:2', 'set:1'])
        ->and($update->oldValue)->toBe('"Acme"')
        ->and($update->newValue)->toBe('"Acme Ltd"')
        ->and($update->causerType->value)->toBe('user')
        ->and($update->causerId)->toBe('7')
        ->and($update->reason)->toBe('INC-9')
        ->and($update->settingRef)->toBe(DB::table('settings')->where('key', 'site.name')->value('uid'))
        ->and($entries[3]->oldValue)->toBeNull()
        ->and($entries[3]->causerType->value)->toBe('system')
        ->and($entries[0]->newValue)->toBe('"Acme Ltd"');
});

it('keeps encrypted values encrypted in the history', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    $secret = TestCredentials::secret('history');

    settings()->set('mail.password', $secret);

    $row = DB::table('settings_history')->where('key', 'mail.password')->first();
    $entry = settings()->history()->key('mail.password')->get()[0];

    expect(json_encode($row))->not->toContain($secret)
        ->and((bool) $row?->new_encrypted)->toBeTrue()
        ->and($row?->new_value)->toBe(DB::table('settings')->where('key', 'mail.password')->value('value'))
        ->and($entry->toArray()['new_value'])->toBeNull()
        ->and($entry->toArray()['new_encrypted'])->toBeTrue();
});

it('writes a tombstone on forget and continues the version when the key is set again', function (): void {
    $user = new OwnerRef('user', 1);
    config()->set('laranail.settings.stores.settings.owners', ['user']);

    settings()->for($user)->set('theme', 'dark');
    settings()->for($user)->forget('theme');
    settings()->for($user)->set('theme', 'light');

    $tombstone = settings()->for($user)->history()->key('theme')->versions(1, 1)->get()[0];

    expect(trail('theme', $user))->toBe(['set:2', 'forget:1', 'set:1'])
        ->and($tombstone->operation)->toBe(HistoryOperation::Forget)
        ->and($tombstone->newValue)->toBeNull()
        ->and($tombstone->oldValue)->toBe('"dark"')
        ->and((int) DB::table('settings')->where('key', 'theme')->value('version'))->toBe(2);
});

it('rolls the change back when its history entry cannot be written', function (): void {
    settings()->set('site.name', 'Acme');
    Schema::drop('settings_history');

    expect(fn () => settings()->set('site.name', 'Changed'))->toThrow(DriverUnavailable::class);

    // The row write ran inside the same transaction, so it is gone too.
    expect(DB::table('settings')->where('key', 'site.name')->value('value'))->toBe('"Acme"')
        ->and((int) DB::table('settings')->where('key', 'site.name')->value('version'))->toBe(1)
        ->and(DB::table('settings_audit')->where('action', 'update')->where('outcome', 'failed')->count())->toBe(1);
});

it('rolls a forget back when its tombstone cannot be written', function (): void {
    settings()->set('site.name', 'Acme');
    DB::statement('drop table settings_history');

    expect(fn () => settings()->forget('site.name'))->toThrow(DriverUnavailable::class)
        ->and(settings()->get('site.name'))->toBe('Acme');
});

it('rolls a setting back to an earlier version through the write pipeline', function (): void {
    Event::fake([SettingRolledBack::class]);
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');

    $version = settings()->because('revert INC-9')->rollback('site.name', 1);
    $latest = settings()->history()->key('site.name')->get(1)[0];

    expect($version)->toBe(3)
        ->and(settings()->get('site.name'))->toBe('Acme')
        ->and($latest->operation)->toBe(HistoryOperation::Rollback)
        ->and($latest->oldValue)->toBe('"Acme Ltd"')
        ->and($latest->newValue)->toBe('"Acme"')
        ->and($latest->reason)->toBe('revert INC-9')
        ->and(DB::table('settings_audit')->where('action', 'rollback')->where('outcome', 'allowed')->value('version_to'))->toEqual(3);

    Event::assertDispatched(SettingRolledBack::class, static fn (SettingRolledBack $event): bool => $event->key === 'site.name' && $event->toVersion === 1 && $event->version === 3);
});

it('rolls back an encrypted setting without exposing the value', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    $first = TestCredentials::secret('first');
    $second = TestCredentials::secret('second');
    expect($first)->not->toBe($second);

    settings()->set('mail.password', $first);
    settings()->set('mail.password', $second);
    settings()->rollback('mail.password', 1);

    expect(settings()->get('mail.password'))->toBe($first)
        ->and(json_encode(DB::table('settings_history')->get()))->not->toContain($first)
        ->and(json_encode(DB::table('settings_audit')->get()))->not->toContain($first);
});

it('refuses a rollback to a version the history does not hold, or to a tombstone', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->forget('site.name');
    settings()->set('site.name', 'Again');

    expect(fn (): int => settings()->rollback('site.name', 9))->toThrow(HistoryUnavailable::class, 'no value at version 9')
        // Version 1 holds a value (the first set) even though a tombstone shares its number.
        ->and(settings()->rollback('site.name', 1))->toBe(3)
        ->and(settings()->get('site.name'))->toBe('Acme');
});

it('gates a rollback by the rollback ability and step-up verification', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');

    expect(fn (): int => settings()->as(user())->rollback('site.name', 1))->toThrow(AccessDenied::class, '[rollback]');

    allowGates(Ability::Rollback);

    // rollback is in verification.abilities by default, and the user has not confirmed a password.
    expect(fn (): int => settings()->as(user())->rollback('site.name', 1))->toThrow(VerificationRequired::class);

    config()->set('laranail.settings.verification.driver', 'actor');
    app()->forgetScopedInstances();
    allowGates(Ability::Rollback);
    $verified = Actor::user(user())->withVerifiedAt(now()->toImmutable());

    expect(settings()->as($verified)->rollback('site.name', 1))->toBe(3)
        ->and(DB::table('settings_audit')->where('action', 'rollback')->where('outcome', 'denied')->count())->toBe(2)
        ->and(DB::table('settings_audit')->where('action', 'rollback')->where('outcome', 'allowed')->value('actor_id'))->toBe('1');
});

it('refuses a rollback over a locked row', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');
    settings()->lock('site.name');

    expect(fn (): int => settings()->rollback('site.name', 1))->toThrow(LockedSetting::class)
        ->and(settings()->get('site.name'))->toBe('Acme Ltd');
});

it('diffs two versions, and decrypts only for an actor who may view the value', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    $first = TestCredentials::secret('first');
    $second = TestCredentials::secret('second');

    settings()->set('mail.password', $first);
    settings()->set('mail.password', $second);
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme');

    $trusted = settings()->historyDiff('mail.password', 1, 2);

    allowGates(Ability::ViewHistory);
    $redacted = settings()->as(user())->historyDiff('mail.password', 1, 2);

    allowGates(Ability::ViewSensitive);
    $revealed = settings()->as(user())->historyDiff('mail.password', 1, 2);
    $plain = settings()->historyDiff('site.name', 1, 2);

    expect([$trusted->from, $trusted->to, $trusted->redacted, $trusted->encrypted])->toBe([$first, $second, false, true])
        ->and([$redacted->from, $redacted->to, $redacted->redacted, $redacted->changed])->toBe([null, null, true, true])
        ->and([$revealed->from, $revealed->to])->toBe([$first, $second])
        ->and([$plain->from, $plain->to, $plain->changed, $plain->fromFound])->toBe(['Acme', 'Acme', false, true])
        ->and(fn (): HistoryDiff => settings()->historyDiff('site.name', 1, 5))->toThrow(HistoryUnavailable::class, 'no version 5');
});

it('needs view_history to read the history, and hides protected payloads from an actor without view_sensitive', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    settings()->set('mail.password', TestCredentials::secret());
    settings()->set('site.name', 'Acme');

    expect(fn (): array => settings()->as(user())->history()->get())->toThrow(AccessDenied::class, '[view_history]');

    allowGates(Ability::ViewHistory);
    $entries = settings()->as(user())->history()->get();
    $byKey = array_column(array_map(static fn (HistoryEntry $entry): array => [$entry->key, $entry], $entries), 1, 0);

    expect($byKey['mail.password']->newValue)->toBeNull()
        ->and($byKey['site.name']->newValue)->toBe('"Acme"');

    allowGates(Ability::ViewSensitive);

    expect(settings()->as(user())->history()->key('mail.password')->get()[0]->newValue)->not->toBeNull();
});

it('filters by owner, locale, time and version, and pages with a cursor', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    $user = new OwnerRef('user', 2);
    app(DefinitionRegistry::class)->define('greeting')->localised()->register();

    $this->travelTo(now()->subDay());
    settings()->set('site.name', 'v1');
    $this->travelBack();

    foreach (range(2, 5) as $n) {
        settings()->set('site.name', 'v' . $n);
    }

    settings()->for($user)->set('site.name', 'mine');
    settings()->locale('fr')->set('greeting', 'Bonjour');

    $first = settings()->history()->global()->key('site.name')->paginate(2);
    $second = settings()->history()->global()->key('site.name')->paginate(2, $first->nextCursor);

    expect(settings()->history()->global()->key('site.name')->versions(2, 3)->get())->toHaveCount(2)
        ->and(settings()->history()->global()->key('site.name')->since(now()->subHour())->get())->toHaveCount(4)
        ->and(settings()->history()->global()->key('site.name')->until(now()->subHour())->get())->toHaveCount(1)
        ->and(settings()->for($user)->history()->get())->toHaveCount(1)
        ->and(settings()->history()->for($user)->key('site.name')->get()[0]->scope)->toBe('user:2')
        ->and(settings()->locale('fr')->history()->get()[0]->key)->toBe('greeting')
        ->and(settings()->history()->locale('fr')->filter()->locale?->value)->toBe('fr')
        ->and(array_map(static fn (HistoryEntry $entry): int => $entry->version, $first->entries))->toBe([5, 4])
        ->and($first->hasMore())->toBeTrue()
        ->and(array_map(static fn (HistoryEntry $entry): int => $entry->version, $second->entries))->toBe([3, 2])
        ->and(settings()->history()->store('settings')->global()->key('site.name')->cursor()->count())->toBe(5);
});

it('keeps no history when the schema was recorded without the table', function (): void {
    useDatabase(['history.enabled' => false]);

    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');
    settings()->forget('site.name');
    settings()->set('site.name', 'Again');

    expect(Schema::hasTable('settings_history'))->toBeFalse()
        ->and((int) DB::table('settings')->where('key', 'site.name')->value('version'))->toBe(1)
        ->and(fn (): array => settings()->history()->get())->toThrow(HistoryUnavailable::class, 'keeps no history')
        ->and(fn (): int => settings()->rollback('site.name', 1))->toThrow(HistoryUnavailable::class, 'cannot be rolled back')
        ->and(fn (): HistoryDiff => settings()->historyDiff('site.name', 1, 1))->toThrow(HistoryUnavailable::class, 'keeps no history');
});

it('rejects a malformed history row rather than inventing an entry', function (): void {
    settings()->set('site.name', 'Acme');
    DB::table('settings_history')->update(['operation' => 'teleport']);

    expect(fn (): array => settings()->history()->get())->toThrow(DriverFailure::class, 'is malformed');
});

it('prunes history by age and by versions kept, audits the prune and announces it', function (): void {
    Event::fake([HistoryPruned::class]);
    $this->travelTo(now()->subDays(40));
    settings()->set('old.key', 'x');
    $this->travelBack();

    foreach (range(1, 5) as $n) {
        settings()->set('site.name', 'v' . $n);
    }

    $byAge = app(PruneHistory::class)->handle(30, null);
    $byVersions = app(PruneHistory::class)->handle(null, 2);

    expect($byAge)->toBe(1)
        ->and($byVersions)->toBe(3)
        ->and(trail('site.name'))->toBe(['set:5', 'set:4'])
        ->and(DB::table('settings_audit')->where('action', 'prune')->count())->toBe(2)
        ->and(fn () => app(PruneHistory::class)->handle(null, null))->toThrow(InvalidConfiguration::class, 'keep_days, keep_versions or both')
        ->and(fn () => app(PruneHistory::class)->handle(0, null))->toThrow(InvalidConfiguration::class, 'keep_days: [0]');

    Event::assertDispatched(HistoryPruned::class, static fn (HistoryPruned $event): bool => $event->removed === 3 && $event->keepVersions === 2 && $event->keepDays === null);
});

it('needs prune to prune the history', function (): void {
    expect(fn () => app(PruneHistory::class)->handle(30, null, Actor::user(user())))->toThrow(AccessDenied::class, '[prune]');
});

it('lists, diffs and rolls back from the history command', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    $secret = TestCredentials::secret('command');
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');
    settings()->set('mail.password', $secret);
    settings()->set('mail.password', TestCredentials::secret('other'));

    $list = Artisan::call('laranail::settings.history', ['key' => 'site.name']);
    $listed = Artisan::output();
    $diff = Artisan::call('laranail::settings.history', ['key' => 'site.name', '--diff' => '1..2']);
    $diffed = Artisan::output();
    Artisan::call('laranail::settings.history', ['key' => 'mail.password', '--diff' => '1..2']);
    $hidden = Artisan::output();
    Artisan::call('laranail::settings.history', ['key' => 'mail.password', '--diff' => '1..2', '--reveal' => true]);
    $revealed = Artisan::output();
    $rollback = Artisan::call('laranail::settings.history', ['key' => 'site.name', '--rollback' => '1']);
    $rolled = Artisan::output();

    expect([$list, $diff, $rollback])->toBe([0, 0, 0])
        ->and($listed)->toContain('set')->toContain('system')
        ->and($diffed)->toContain('v1: "Acme"')->toContain('v2: "Acme Ltd"')->toContain('The value changed.')
        ->and($hidden)->toContain('[protected]')->not->toContain($secret)
        ->and($revealed)->toContain($secret)
        ->and($rolled)->toContain('now at version 3')
        ->and(settings()->get('site.name'))->toBe('Acme')
        ->and(DB::table('settings_audit')->where('action', 'rollback')->value('reason'))->toBe('laranail::settings.history');
});

it('reports bad history command input and an empty history', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);

    expect(Artisan::call('laranail::settings.history', ['key' => 'nothing.here']))->toBe(0)
        ->and(Artisan::output())->toContain('No history for [nothing.here]')
        ->and(Artisan::call('laranail::settings.history', ['key' => 'site.name', '--diff' => '1-2']))->toBe(1)
        ->and(Artisan::output())->toContain('FROM..TO')
        ->and(Artisan::call('laranail::settings.history', ['key' => 'site.name', '--rollback' => 'x']))->toBe(1)
        ->and(Artisan::output())->toContain('version number')
        ->and(Artisan::call('laranail::settings.history', ['key' => 'site.name', '--rollback' => '4']))->toBe(1)
        ->and(Artisan::output())->toContain('no value at version 4')
        ->and(Artisan::call('laranail::settings.history', ['key' => 'site.name', '--owner' => 'nonsense']))->toBe(1)
        ->and(Artisan::output())->toContain('alias:id')
        ->and(Artisan::call('laranail::settings.history', ['key' => 'site.name', '--owner' => 'user:3', '--store' => 'settings', '--locale' => 'fr', '--limit' => '5']))->toBe(0);
});

it('prunes from the prune-history command with the configured or given retention', function (): void {
    foreach (range(1, 4) as $n) {
        settings()->set('site.name', 'v' . $n);
    }

    config()->set('laranail.settings.history.keep_days');
    config()->set('laranail.settings.history.keep_versions');

    expect(Artisan::call('laranail::settings.prune-history'))->toBe(0)
        ->and(Artisan::output())->toContain('nothing to prune')
        ->and(Artisan::call('laranail::settings.prune-history', ['--versions' => 'many']))->toBe(1)
        ->and(Artisan::call('laranail::settings.prune-history', ['--versions' => '0']))->toBe(1)
        ->and(Artisan::output())->toContain('at least 1')
        ->and(Artisan::call('laranail::settings.prune-history', ['--versions' => '1', '--days' => '30']))->toBe(0)
        ->and(Artisan::output())->toContain('Removed 3 history entries')
        ->and(DB::table('settings_audit')->where('action', 'prune')->value('actor_type'))->toBe('system');

    config()->set('laranail.settings.history.keep_versions', 1);

    expect(Artisan::call('laranail::settings.prune-history'))->toBe(0)
        ->and(Artisan::output())->toContain('Removed 0 history entries');
});

it('records the tenant on history rows and reads only the current tenant\'s', function (): void {
    useDatabase(['tenancy.mode' => 'column']);
    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): string => 'acme'));

    settings()->set('site.name', 'Acme');
    $row = (array) DB::table('settings_history')->first();
    DB::table('settings_history')->insert(['id' => (string) Str::ulid(), 'tenant' => 'other'] + $row);

    expect(DB::table('settings_history')->orderBy('tenant')->pluck('tenant')->all())->toBe(['acme', 'other'])
        ->and(settings()->history()->get())->toHaveCount(1)
        // Retention is application-wide: each tenant keeps its own newest version.
        ->and(app(PruneHistory::class)->handle(null, 1))->toBe(0);
});
