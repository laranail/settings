<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Events\ChangeStale;
use Simtabi\Laranail\Settings\Events\ChangeApplied;
use Illuminate\Database\Events\TransactionBeginning;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Events\ChangeApproved;
use Simtabi\Laranail\Settings\Events\ChangeRejected;
use Simtabi\Laranail\Settings\Events\ChangeCancelled;
use Simtabi\Laranail\Settings\Events\ChangeRequested;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Actions\Approvals\QueryChanges;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * A write to a setting that needs approval becomes a change request; approvals apply it
 * through the write pipeline against the version it was opened at; the requester cannot approve
 * their own; rejected, cancelled, stale and expired requests write nothing.
 */

beforeEach(function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);
    allowGates(Ability::Update, Ability::Forget, Ability::Lock, Ability::Unlock, Ability::Approve, Ability::View);
});

function needsApproval(string $key = 'mail.host'): void
{
    app(DefinitionRegistry::class)->define($key)->requiresApproval()->register();
}

it('turns a write to a setting that needs approval into a pending change request', function (): void {
    Event::fake([ChangeRequested::class]);
    needsApproval();
    $audit = auditInMemory();

    settings()->as(user(1))->because('INC-1')->set('mail.host', 'smtp.acme.test');

    $requests = settings()->approvals()->pending()->get();

    expect(settings()->get('mail.host'))->toBeNull()
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->operation)->toBe(ChangeOperation::Set)
        ->and($requests[0]->status)->toBe(ChangeStatus::Pending)
        ->and($requests[0]->reason)->toBe('INC-1')
        ->and($requests[0]->requestedById)->toBe('1')
        ->and($requests[0]->baseVersion)->toBe(0)
        ->and($requests[0]->expiresAt?->diffInDays(now()->toImmutable(), true))->toBeGreaterThan(6.9)
        ->and($requests[0]->toArray())->not->toHaveKey('payload')
        ->and(collect($audit->entries())->contains(fn ($entry): bool => $entry->action === AuditAction::Propose && $entry->outcome === AuditOutcome::Pending && $entry->meta['change_request'] === $requests[0]->id))->toBeTrue()
        ->and(DB::table('settings_audit')->where('change_request', $requests[0]->id)->count())->toBe(1);

    Event::assertDispatched(ChangeRequested::class, fn (ChangeRequested $event): bool => $event->key === 'mail.host');
});

it('matches store approval globs as well as definitions', function (): void {
    useDatabase(['stores.settings.approvals' => ['keys' => ['billing.*']]]);

    settings()->set('billing.plan', 'pro');
    settings()->set('site.name', 'Acme');

    expect(settings()->get('billing.plan'))->toBeNull()
        ->and(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->approvals()->get())->toHaveCount(1);
});

it('applies an approved request through the write pipeline and records who approved it', function (): void {
    Event::fake([ChangeApproved::class, ChangeApplied::class]);
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');
    $request = settings()->approvals()->get()[0];

    $approved = settings()->as(user(2))->approvals()->approve($request->id, 'looks right');

    expect($approved->status)->toBe(ChangeStatus::Applied)
        ->and($approved->approvals)->toBe(['user:' . user(2)->getMorphClass() . ':2'])
        ->and($approved->decidedById)->toBe('2')
        ->and($approved->decisionReason)->toBe('looks right')
        ->and(settings()->get('mail.host'))->toBe('smtp.acme.test')
        ->and(settings()->history()->key('mail.host')->get()[0]->causerId)->toBe('2');

    Event::assertDispatched(ChangeApplied::class, fn (ChangeApplied $event): bool => $event->version === 1);
    expect(fn (): ChangeRequest => settings()->approvals()->approve($request->id))->toThrow(ChangeRequestClosed::class, 'applied');
});

it('refuses self-approval unless allowed', function (): void {
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');
    $id = settings()->approvals()->get()[0]->id;

    expect(fn (): ChangeRequest => settings()->as(user(1))->approvals()->approve($id))->toThrow(AccessDenied::class, 'own change request')
        ->and(DB::table('settings_audit')->where('outcome', 'denied')->where('change_request', $id)->count())->toBe(1);

    config()->set('laranail.settings.approvals.allow_self', true);
    app()->forgetScopedInstances();

    expect(settings()->as(user(1))->approvals()->approve($id)->status)->toBe(ChangeStatus::Applied);
});

it('waits for the minimum number of distinct approvals', function (): void {
    config()->set('laranail.settings.approvals.min_approvals', 2);
    app()->forgetScopedInstances();
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');
    $id = settings()->approvals()->get()[0]->id;

    $first = settings()->as(user(2))->approvals()->approve($id);
    $again = settings()->as(user(2))->approvals()->approve($id);

    expect($first->status)->toBe(ChangeStatus::Approved)
        ->and($again->approvals)->toHaveCount(1)
        ->and(settings()->get('mail.host'))->toBeNull()
        ->and(settings()->as(user(3))->approvals()->approve($id)->status)->toBe(ChangeStatus::Applied)
        ->and(settings()->get('mail.host'))->toBe('smtp.acme.test');
});

it('counts a user and their API token as one person: no self-approval, no second vote (SP-03)', function (): void {
    config()->set('laranail.settings.approvals.min_approvals', 2);
    app()->forgetScopedInstances();
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');
    $id = settings()->approvals()->get()[0]->id;

    expect(fn (): ChangeRequest => settings()->as(Actor::user(user(1), abilities: ['*']))->approvals()->approve($id))->toThrow(AccessDenied::class, 'own change request');

    settings()->as(user(2))->approvals()->approve($id);
    $again = settings()->as(Actor::user(user(2), abilities: ['*']))->approvals()->approve($id);

    expect($again->status)->toBe(ChangeStatus::Approved)
        ->and($again->approvals)->toBe(['user:' . user(2)->getMorphClass() . ':2'])
        ->and(settings()->get('mail.host'))->toBeNull()
        ->and(settings()->as(user(3))->approvals()->approve($id)->status)->toBe(ChangeStatus::Applied);
});

it('reads an approval recorded for a token before 0.1.1 as its user\'s (SP-03)', function (): void {
    config()->set('laranail.settings.approvals.min_approvals', 2);
    app()->forgetScopedInstances();
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');
    $id = settings()->approvals()->get()[0]->id;
    DB::table('settings_change_requests')->where('id', $id)->update(['status' => 'approved', 'approvals' => json_encode(['token:' . user(2)->getMorphClass() . ':2'])]);

    expect(settings()->as(user(2))->approvals()->approve($id)->status)->toBe(ChangeStatus::Approved);
});

it('pages an actor\'s own change requests in the query, not after it (SP-05)', function (): void {
    needsApproval('mail.host');
    needsApproval('mail.port');
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');

    foreach ([2, 3, 4] as $id) {
        settings()->as(user($id))->set('mail.port', $id);
    }

    Gate::define(Ability::Approve->gateName(), static fn ($user): bool => $user->getAuthIdentifier() !== 1);
    $own = app(QueryChanges::class)->handle(new ChangeFilter(new StoreName('settings')), Actor::user(user(1)), 3);
    $asToken = app(QueryChanges::class)->handle(new ChangeFilter(new StoreName('settings')), Actor::user(user(1), abilities: ['*']), 3);

    expect(array_map(static fn (ChangeRequest $request): string => $request->key, $own))->toBe(['mail.host'])
        ->and($asToken)->toHaveCount(1)
        ->and(app(QueryChanges::class)->handle(new ChangeFilter(new StoreName('settings')), Actor::user(user(2)), 3))->toHaveCount(3);
});

it('counts two approvals recorded at once, never losing one', function (): void {
    config()->set('laranail.settings.approvals.min_approvals', 3);
    app()->forgetScopedInstances();
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'smtp.acme.test');
    $id = settings()->approvals()->get()[0]->id;

    // User 3's approval commits after user 2 read the request and before user 2 records theirs.
    $raced = false;
    Event::listen(TransactionBeginning::class, static function () use (&$raced, $id): void {
        if (! $raced) {
            $raced = true;
            DB::table('settings_change_requests')->where('id', $id)->update(['status' => 'approved', 'approvals' => json_encode([ChangeRequest::actorKey(Actor::user(user(3)))])]);
        }
    });

    $approved = settings()->as(user(2))->approvals()->approve($id);

    expect($raced)->toBeTrue()
        ->and($approved->status)->toBe(ChangeStatus::Approved)
        ->and($approved->approvals)->toHaveCount(2);
});

it('marks a request stale when its setting changed after it was opened, and never overwrites', function (): void {
    Event::fake([ChangeStale::class]);
    useDatabase(['stores.settings.approvals' => ['keys' => ['mail.*']]]);
    settings()->propose('mail.host', 'first.test');
    $id = settings()->approvals()->get()[0]->id;
    // Somebody writes the key directly (a key the globs no longer cover).
    config()->set('laranail.settings.stores.settings.approvals.keys', []);
    app()->forgetScopedInstances();
    settings()->set('mail.host', 'direct.test');

    $result = settings()->approvals()->approve($id);

    expect($result->status)->toBe(ChangeStatus::Stale)
        ->and(settings()->get('mail.host'))->toBe('direct.test');
    Event::assertDispatched(ChangeStale::class);
});

it('rejects and cancels without writing', function (): void {
    Event::fake([ChangeRejected::class, ChangeCancelled::class]);
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'a.test');
    settings()->as(user(1))->set('mail.host', 'b.test');
    [$second, $first] = settings()->approvals()->get();

    $rejected = settings()->as(user(2))->approvals()->reject($first->id, 'no');

    expect(fn (): ChangeRequest => settings()->as(user(2))->approvals()->cancel($second->id))->toThrow(AccessDenied::class, 'only the requester');

    $cancelled = settings()->as(user(1))->approvals()->cancel($second->id);

    expect($rejected->status)->toBe(ChangeStatus::Rejected)
        ->and($rejected->decisionReason)->toBe('no')
        ->and($cancelled->status)->toBe(ChangeStatus::Cancelled)
        ->and(settings()->get('mail.host'))->toBeNull()
        ->and(fn (): ChangeRequest => settings()->approvals()->reject($first->id))->toThrow(ChangeRequestClosed::class);
    Event::assertDispatched(ChangeRejected::class);
    Event::assertDispatched(ChangeCancelled::class);
});

it('never decides a change request through another store, where it would be authorized and applied', function (): void {
    useDatabase(['stores.billing' => ['layers' => ['database'], 'approvals' => ['keys' => ['plan.*']]]], ['billing']);
    allowGates(Ability::Update, Ability::Approve, Ability::View);
    // An approver of the settings store only.
    Gate::define(Ability::Approve->gateName(), static fn ($user, Subject $subject): bool => $subject->store->value === 'settings');
    $change = settings()->store('billing')->as(user(1))->propose('plan.tier', 'gold');

    foreach (['approve', 'reject', 'cancel'] as $decision) {
        expect(fn (): ChangeRequest => settings()->as(user(2))->approvals()->{$decision}($change->id))->toThrow(UnknownChangeRequest::class, 'Store [settings]');
    }

    expect(settings()->get('plan.tier'))->toBeNull()
        ->and(settings()->store('billing')->get('plan.tier'))->toBeNull()
        ->and(DB::table('settings_change_requests')->value('status'))->toBe('pending')
        ->and(settings()->store('billing')->approvals()->find($change->id)->store)->toBe('billing');
});

it('proposes forget, lock and unlock as well as values', function (): void {
    settings()->for(new OwnerRef('user', 5))->set('mail.host', 'a.test');
    needsApproval();
    $owner = settings()->as(user(1))->for(new OwnerRef('user', 5));

    $owner->lock('mail.host');
    $lock = settings()->approvals()->for(new OwnerRef('user', 5))->get()[0];
    settings()->as(user(2))->approvals()->approve($lock->id);

    $owner->forget('mail.host');
    $forget = settings()->approvals()->key('mail.host')->pending()->get()[0];

    expect($lock->operation)->toBe(ChangeOperation::Lock)
        ->and($lock->baseVersion)->toBe(1)
        ->and(DB::table('settings')->where('scope', 'user:5')->value('is_locked'))->toBeTruthy()
        ->and($forget->operation)->toBe(ChangeOperation::Forget);
});

it('keeps the proposed value of an encrypted setting encrypted at rest', function (): void {
    $secret = TestCredentials::secret();
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->requiresApproval()->register();

    settings()->as(user(1))->set('mail.password', $secret);
    $row = DB::table('settings_change_requests')->first();

    expect($row->payload)->not->toContain($secret)
        ->and((bool) $row->is_encrypted)->toBeTrue();

    settings()->as(user(2))->approvals()->approve($row->id);

    expect(settings()->get('mail.password'))->toBe($secret);
});

it('lets an actor without approve see only their own requests', function (): void {
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'a.test');
    settings()->as(user(2))->set('mail.host', 'b.test');
    Gate::define(Ability::Approve->gateName(), static fn ($user): bool => $user->getAuthIdentifier() === 3);

    $mine = settings()->as(user(1))->approvals()->get();

    expect($mine)->toHaveCount(1)
        ->and($mine[0]->requestedById)->toBe('1')
        ->and(settings()->as(user(3))->approvals()->get())->toHaveCount(2)
        ->and(settings()->as(user(1))->approvals()->find($mine[0]->id)->id)->toBe($mine[0]->id)
        ->and(fn (): ChangeRequest => settings()->approvals()->find('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toThrow(UnknownChangeRequest::class);
});

it('expires overdue requests and prunes decided ones', function (): void {
    needsApproval();
    settings()->as(user(1))->set('mail.host', 'a.test');
    settings()->as(user(1))->set('mail.host', 'b.test');
    [$late, $early] = settings()->approvals()->get();
    settings()->as(user(2))->approvals()->reject($late->id);

    $this->travel(8)->days();

    expect(fn (): ChangeRequest => settings()->as(user(2))->approvals()->approve($early->id))->toThrow(ChangeRequestClosed::class, 'expired');

    $this->travel(100)->days();
    $exit = Artisan::call('laranail::settings.prune-changes');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('deleted 2')
        ->and(DB::table('settings_change_requests')->count())->toBe(0);
});

it('refuses approval where a store cannot hold change requests', function (): void {
    // File layers only: no database layer to hold the request.
    config()->set('laranail.settings.stores.settings.layers', ['runtime', 'shipped']);
    app()->forgetScopedInstances();
    needsApproval();

    expect(fn () => settings()->set('mail.host', 'a.test'))->toThrow(ApprovalUnavailable::class, 'cannot hold change requests')
        ->and(fn (): ChangeRequest => settings()->propose('mail.host', 'a.test'))->toThrow(ApprovalUnavailable::class);
});

it('never lists a payload, so a change request is safe to render', function (): void {
    needsApproval();
    settings()->set('mail.host', 'a.test');
    $request = settings()->approvals()->get()[0];

    expect($request)->toBeInstanceOf(ChangeRequest::class)
        ->and(json_encode($request->toArray()))->not->toContain('a.test');
});
