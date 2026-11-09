<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Enums\Verdict;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Data\ActorContext;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Verification\ClosureVerifier;
use Simtabi\Laranail\Settings\Authorization\ChainAuthorizer;
use Simtabi\Laranail\Settings\Authorization\ClosureAuthorizer;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Verification\ActorTimestampVerifier;

function subjectFor(string $key = 'site.name'): Subject
{
    return new Subject(new StoreName('settings'), new Key($key));
}

it('names each ability\'s gate and token ability, and knows the read abilities', function (): void {
    expect(Ability::Update->gateName())->toBe('laranail-settings.update')
        ->and(Ability::OverrideLock->tokenAbility())->toBe('settings:override_lock')
        ->and(Ability::cases())->toHaveCount(17)
        ->and(array_values(array_map(static fn (Ability $ability): string => $ability->value, array_filter(Ability::cases(), static fn (Ability $ability): bool => $ability->isRead()))))
        ->toBe(['view', 'view_sensitive', 'view_history', 'view_audit']);
});

it('has an audit action for every ability plus configure', function (): void {
    foreach (Ability::cases() as $ability) {
        expect(AuditAction::fromAbility($ability)->value)->toBe($ability->value);
    }

    expect(AuditAction::cases())->toHaveCount(18)
        ->and(AuditAction::Configure->value)->toBe('configure');
});

it('builds each kind of decision', function (): void {
    expect(Decision::allow()->isAllowed())->toBeTrue()
        ->and(Decision::deny('no')->isDenied())->toBeTrue()
        ->and(Decision::deny('no')->reason)->toBe('no')
        ->and(Decision::requiresVerification()->needsVerification())->toBeTrue()
        ->and(Decision::requiresApproval()->needsApproval())->toBeTrue()
        ->and(Decision::abstain()->isAbstention())->toBeTrue()
        ->and(Decision::abstain()->isAllowed())->toBeFalse()
        ->and(Decision::allow()->verdict)->toBe(Verdict::Allow)
        ->and(Decision::deny()->isAllowed())->toBeFalse();
});

it('takes a subject\'s flags from the definition, encryption implying sensitivity', function (): void {
    $store = new StoreName('settings');
    $key = new Key('mail.api_key');
    $owner = new OwnerRef('team', 3);

    $plain = Subject::of($store, $key, Locale::none(), null, null);
    $encrypted = Subject::of($store, $key, new Locale('fr'), $owner, new Definition($store, $key, encrypted: true, category: 'mail', requiresVerification: true, requiresApproval: true));

    expect($plain->sensitive)->toBeFalse()
        ->and($plain->scope()->value)->toBe('global')
        ->and($encrypted->sensitive)->toBeTrue()
        ->and($encrypted->category)->toBe('mail')
        ->and($encrypted->requiresVerification)->toBeTrue()
        ->and($encrypted->requiresApproval)->toBeTrue()
        ->and($encrypted->scope()->value)->toBe('team:3')
        ->and($encrypted->locale->value)->toBe('fr');
});

it('maps closure results onto decisions', function (mixed $result, Verdict $verdict): void {
    $authorizer = new ClosureAuthorizer(static fn (): mixed => $result);

    expect($authorizer->authorize(Actor::system(), Ability::View, subjectFor())->verdict)->toBe($verdict);
})->with([
    'true'     => [true, Verdict::Allow],
    'false'    => [false, Verdict::Deny],
    'null'     => [null, Verdict::Abstain],
    'decision' => [Decision::requiresVerification(), Verdict::RequiresVerification],
]);

it('lets the first non-abstaining authorizer in a chain decide, and abstains when none does', function (): void {
    $abstain = new ClosureAuthorizer(static fn (): null => null);
    $deny = new ClosureAuthorizer(static fn (): bool => false);
    $allow = new ClosureAuthorizer(static fn (): bool => true);

    expect(new ChainAuthorizer([$abstain, $deny, $allow])->authorize(Actor::system(), Ability::View, subjectFor())->isDenied())->toBeTrue()
        ->and(new ChainAuthorizer([$abstain, $allow, $deny])->authorize(Actor::system(), Ability::View, subjectFor())->isAllowed())->toBeTrue()
        ->and(new ChainAuthorizer([$abstain])->authorize(Actor::system(), Ability::View, subjectFor())->isAbstention())->toBeTrue()
        ->and(new ChainAuthorizer([])->authorize(Actor::system(), Ability::View, subjectFor())->reason)->toBe('no authorizer decided');
});

it('treats a verification as fresh within the ttl and not in the future', function (): void {
    $now = CarbonImmutable::parse('2026-11-09 12:00:00');

    expect(ActorTimestampVerifier::fresh($now->subSeconds(60), $now, 60))->toBeTrue()
        ->and(ActorTimestampVerifier::fresh($now->subSeconds(61), $now, 60))->toBeFalse()
        ->and(ActorTimestampVerifier::fresh($now, $now, 0))->toBeTrue()
        ->and(ActorTimestampVerifier::fresh($now->addSecond(), $now, 60))->toBeFalse()
        ->and(ActorTimestampVerifier::fresh(null, $now, 60))->toBeFalse();
});

it('verifies through a closure only on a literal true', function (): void {
    expect(new ClosureVerifier(static fn (): bool => true)->isVerified(Actor::system(), subjectFor()))->toBeTrue()
        ->and(new ClosureVerifier(static fn (): string => 'yes')->isVerified(Actor::system(), subjectFor()))->toBeFalse();
});

it('names the ability, store and key in access exceptions', function (): void {
    $denied = AccessDenied::for(Ability::Forget, subjectFor('a.b'), 'nope');
    $unkeyed = AccessDenied::for(Ability::ManageStores, new Subject(new StoreName('settings')));
    $verify = VerificationRequired::for(Ability::Rollback, subjectFor('a.b'));

    expect($denied->getMessage())->toBe('Access denied: [forget] on [settings] key [a.b] (nope).')
        ->and($denied->reason)->toBe('nope')
        ->and($unkeyed->getMessage())->toBe('Access denied: [manage_stores] on [settings].')
        ->and($unkeyed->key)->toBeNull()
        ->and($verify->getMessage())->toBe('Verification required: [rollback] on [settings] key [a.b] needs a recent identity check.')
        ->and($verify->ability)->toBe(Ability::Rollback)
        ->and(VerificationRequired::for(Ability::Import, new Subject(new StoreName('settings')))->getMessage())->toBe('Verification required: [import] on [settings] needs a recent identity check.');
});

it('builds an actor for a non-model authenticatable with a generic alias', function (): void {
    $actor = Actor::user(new GenericUser(['id' => 5]));

    expect($actor->alias)->toBe('user')
        ->and($actor->id)->toBe('5')
        ->and($actor->toArray())->toBe(['type' => 'user', 'alias' => 'user', 'id' => '5', 'channel' => 'php']);
});

it('keeps an actor\'s other fields when deriving copies', function (): void {
    $at = CarbonImmutable::parse('2026-11-09 12:00:00');
    $actor = Actor::user(new GenericUser(['id' => 5]), abilities: ['settings:view'])
        ->withVerifiedAt($at)
        ->withChannel(Channel::Console)
        ->withContext(new ActorContext('1.2.3.4'))
        ->withReason('why');

    expect($actor->verifiedAt)->toBe($at)
        ->and($actor->channel->value)->toBe('console')
        ->and($actor->context->ip)->toBe('1.2.3.4')
        ->and($actor->context->reason)->toBe('why')
        ->and($actor->abilities)->toBe(['settings:view'])
        ->and($actor->id)->toBe('5');
});
