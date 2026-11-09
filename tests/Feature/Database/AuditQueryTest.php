<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;

beforeEach(function (): void {
    useDatabase([
        'stores.settings.owners' => ['user'],
        'stores.billing'         => ['layers' => ['database']],
    ], stores: ['billing']);
});

/** @param list<AuditEntry> $entries */
function auditKeys(array $entries): array
{
    return array_map(static fn (AuditEntry $entry): string => $entry->action->value . ':' . ($entry->key ?? '-'), $entries);
}

it('queries the audit trail by owner, store, key, action, outcome and time, newest first (§16.8)', function (): void {
    $this->travelTo(now()->subDays(3));
    settings()->set('site.name', 'Old');
    $this->travelBack();
    settings()->set('site.name', 'Acme');
    settings()->for(new OwnerRef('user', 5))->set('theme', 'dark');
    settings()->for(new OwnerRef('user', 5))->lock('theme');
    settings()->store('billing')->set('currency', 'EUR');

    expect(auditKeys(settings()->audit()->for(new OwnerRef('user', 5))->get()))->toBe(['lock:theme', 'update:theme'])
        ->and(auditKeys(settings()->audit()->store('billing')->get()))->toBe(['update:currency'])
        ->and(auditKeys(settings()->store('billing')->audit()->get()))->toBe(['update:currency'])
        ->and(auditKeys(settings()->audit()->key('site.name')->since(now()->subDay())->get()))->toBe(['update:site.name'])
        ->and(auditKeys(settings()->audit()->key('site.name')->until(now()->subDay())->get()))->toBe(['update:site.name'])
        ->and(settings()->audit()->action(AuditAction::Lock)->outcome(AuditOutcome::Allowed)->get())->toHaveCount(1)
        ->and(auditKeys(settings()->audit()->store('settings')->global()->get(1)))->toBe(['update:site.name'])
        ->and(settings()->audit()->for(new OwnerRef('user', 5))->filter()->scope?->value)->toBe('user:5');
});

it('streams and pages the trail, and maps every column back', function (): void {
    foreach (range(1, 5) as $n) {
        settings()->asSystem('batch')->set("k.n{$n}", $n);
    }

    $first = settings()->audit()->paginate(2);
    $second = settings()->audit()->paginate(2, $first->nextCursor);
    $last = settings()->audit()->paginate(2, settings()->audit()->paginate(2, $second->nextCursor)->previousCursor);
    $entry = settings()->audit()->cursor()->first();

    expect($first->entries)->toHaveCount(2)
        ->and($first->hasMore())->toBeTrue()
        ->and($first->previousCursor)->toBeNull()
        ->and($first->perPage)->toBe(2)
        ->and($second->entries[0]->key)->not->toBe($first->entries[0]->key)
        ->and($last->entries[0]->key)->toBe($second->entries[0]->key)
        ->and(settings()->audit()->paginate(10)->hasMore())->toBeFalse()
        ->and(settings()->audit()->cursor()->count())->toBe(5)
        ->and($entry?->reason)->toBe('batch')
        ->and($entry?->versionTo)->toBe(1)
        ->and($entry?->actorType->value)->toBe('system');
});

it('needs view_audit when an actor queries, and records the denial', function (): void {
    settings()->set('site.name', 'Acme');

    expect(fn (): array => settings()->as(user())->audit()->get())->toThrow(AccessDenied::class);

    allowGates(Ability::ViewAudit);

    expect(settings()->as(user())->audit()->outcome(AuditOutcome::Denied)->get()[0]->action)->toBe(AuditAction::ViewAudit);
});

it('cannot query a store whose trail is not in a database', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['runtime']);
    config()->set('laranail.settings.stores.billing.layers', ['runtime']);
    app()->forgetScopedInstances();

    expect(fn (): array => settings()->audit()->get())->toThrow(DriverUnavailable::class, 'cannot be queried');
});

it('refuses a malformed audit row rather than inventing an entry, and ignores malformed meta', function (): void {
    settings()->set('site.name', 'Acme');
    DB::table('settings_audit')->update(['meta' => 'not json']);

    expect(settings()->audit()->get()[0]->meta)->toBe([]);

    DB::table('settings_audit')->update(['action' => 'teleport']);

    expect(fn (): array => settings()->audit()->get())->toThrow(DriverFailure::class, 'malformed');
});
