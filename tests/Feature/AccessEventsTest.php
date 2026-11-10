<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Events\AuditPruned;
use Simtabi\Laranail\Settings\Events\CategoryMoved;
use Simtabi\Laranail\Settings\Events\HistoryPruned;
use Simtabi\Laranail\Settings\Events\OrphansPruned;
use Simtabi\Laranail\Settings\Events\SettingLocked;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;
use Simtabi\Laranail\Settings\Events\CategoryRenamed;
use Simtabi\Laranail\Settings\Events\SettingUnlocked;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Events\DefinitionsSynced;
use Simtabi\Laranail\Settings\Events\SettingRolledBack;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Simtabi\Laranail\Settings\Events\VerificationWasRequired;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;

/*
 * Lock, refusal and maintenance events. None carries a value.
 */

it('announces a lock and an unlock with identifiers and the new version', function (): void {
    Event::fake([SettingLocked::class, SettingUnlocked::class]);

    settings()->set('site.name', 'Acme');
    settings()->lock('site.name');
    settings()->unlock('site.name');

    Event::assertDispatched(SettingLocked::class, static fn (SettingLocked $event): bool => $event->key === 'site.name' && $event->scope === 'global' && $event->version === 2);
    Event::assertDispatched(SettingUnlocked::class, static fn (SettingUnlocked $event): bool => $event->version === 3);
});

it('announces a refusal at once, with the ability, the subject and the actor', function (): void {
    Event::fake([AccessWasDenied::class]);

    expect(fn () => settings()->as(user(5))->set('site.name', 'x'))->toThrow(AccessDenied::class);

    Event::assertDispatched(AccessWasDenied::class, static fn (AccessWasDenied $event): bool => $event->ability === Ability::Update
        && $event->subject->key?->value === 'site.name'
        && $event->actor?->id === '5'
        && $event->refusal === 'access_denied');
});

it('announces a call refused for want of an actor', function (): void {
    Event::fake([AccessWasDenied::class]);
    settings()->configuration()->requireActor();

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(ActorRequired::class);

    Event::assertDispatched(AccessWasDenied::class, static fn (AccessWasDenied $event): bool => ! $event->actor instanceof Actor && $event->refusal === 'actor_required');
});

it('announces an actor stopped for step-up verification', function (): void {
    Event::fake([VerificationWasRequired::class, AccessWasDenied::class]);
    allowGates(Ability::Rollback);

    expect(fn (): int => settings()->as(user())->rollback('site.name', 1))->toThrow(VerificationRequired::class);

    Event::assertDispatched(VerificationWasRequired::class, static fn (VerificationWasRequired $event): bool => $event->ability === Ability::Rollback && $event->actor->id === '1');
    Event::assertNotDispatched(AccessWasDenied::class);
});

it('keeps values out of every new event and dispatches the changes after commit', function (): void {
    $events = [SettingLocked::class, SettingUnlocked::class, SettingRolledBack::class, AccessWasDenied::class, VerificationWasRequired::class, DefinitionsSynced::class, AuditPruned::class, HistoryPruned::class, OrphansPruned::class, CategoryMoved::class, CategoryRenamed::class];
    $properties = [];

    foreach ($events as $class) {
        $properties = [...$properties, ...array_keys(get_class_vars($class))];
    }

    $afterCommit = array_filter($events, static fn (string $class): bool => is_subclass_of($class, ShouldDispatchAfterCommit::class));

    expect(count($events))->toBe(11)
        ->and(array_intersect($properties, ['value', 'payload', 'old_value', 'new_value', 'oldValue', 'newValue']))->toBe([])
        // Refusals are announced at once (the refusal throws and rolls its transaction back).
        ->and(array_values(array_diff($events, $afterCommit)))->toBe([AccessWasDenied::class, VerificationWasRequired::class]);
});
