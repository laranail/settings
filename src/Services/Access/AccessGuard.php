<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Access;

use Psr\Log\NullLogger;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Support\Clock;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Verification\NullVerifier;
use Simtabi\Laranail\Settings\Events\VerificationWasRequired;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Authorization\ClosureAuthorizer;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Authentication\ClosureActorResolver;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * Enforces access for one call: the actor requirement, the token ceiling, the authorizer, the
 * undefined-ability policy and step-up verification. Every denial is logged, audited and
 * announced ({@see AccessWasDenied}, {@see VerificationWasRequired}) before it is thrown. Trusted
 * calls (no actor, or an explicit system actor) are never authorized.
 *
 * Bound `scoped`: it reads the runtime configuration of the current request.
 */
final readonly class AccessGuard
{
    /** @param list<Ability> $verificationAbilities abilities that always need step-up verification */
    public function __construct(
        private Authorizer $authorizer,
        private Verifier $verifier,
        private ActorResolver $resolver,
        private Auditor $auditor,
        private LoggerInterface $logger,
        private array $verificationAbilities = [],
        private bool $undefinedAllows = false,
        private bool $requireActor = false,
        private ?RuntimeConfiguration $runtime = null,
        private ?Dispatcher $events = null,
    ) {}

    /** A guard that denies every actor and audits nowhere: the safe default for anything built by hand. */
    public static function closed(): self
    {
        return new self(
            new ClosureAuthorizer(static fn (): null => null),
            new NullVerifier,
            new ClosureActorResolver(static fn (): null => null),
            new Auditor([], new Clock),
            new NullLogger,
        );
    }

    /** A copy auditing through another auditor (the fake records entries in memory with it). */
    public function withAuditor(Auditor $auditor): self
    {
        return new self(
            $this->authorizer,
            $this->verifier,
            $this->resolver,
            $auditor,
            $this->logger,
            $this->verificationAbilities,
            $this->undefinedAllows,
            $this->requireActor,
            $this->runtime,
            $this->events,
        );
    }

    public function auditor(): Auditor
    {
        return $this->auditor;
    }

    /** The actor the configured (or runtime) resolver finds for this request, if any. */
    public function resolveActor(?Request $request = null): ?Actor
    {
        return ($this->runtime?->actorResolver() ?? $this->resolver)->resolve($request);
    }

    public function requiresActor(): bool
    {
        return $this->runtime?->actorRequirement() ?? $this->requireActor;
    }

    /**
     * The decision for an actor, with the token ceiling applied and abstentions resolved by
     * `authorization.undefined`. Never throws and never audits.
     */
    public function decide(Actor $actor, Ability $ability, Subject $subject): Decision
    {
        if ($actor->isSystem()) {
            return Decision::allow();
        }

        if (! $actor->tokenAllows($ability)) {
            return Decision::deny(sprintf('the token lacks [%s]', $ability->tokenAbility()));
        }

        $decision = ($this->runtime?->authorizer() ?? $this->authorizer)->authorize($actor, $ability, $subject);

        if ($decision->isAbstention()) {
            return $this->undefinedAllows ? Decision::allow() : Decision::deny($decision->reason ?? 'ability not defined');
        }

        return $decision;
    }

    /** Whether the actor may do this right now, without throwing or auditing (used to redact). */
    public function allows(Actor $actor, Ability $ability, Subject $subject): bool
    {
        return $this->decide($actor, $ability, $subject)->isAllowed();
    }

    /**
     * Enforce one ability. A null actor is trusted unless an actor is required.
     *
     * @throws ActorRequired
     * @throws AccessDenied
     * @throws VerificationRequired
     * @throws ApprovalUnavailable
     */
    public function authorize(?Actor $actor, Ability $ability, Subject $subject): void
    {
        if (! $actor instanceof Actor) {
            $this->assertActorNotRequired($ability, $subject);

            return;
        }

        if ($actor->isSystem()) {
            return;
        }

        $decision = $this->decide($actor, $ability, $subject);

        if ($decision->isDenied()) {
            $this->refuse($actor, $ability, $subject, 'access_denied', $decision->reason);

            throw AccessDenied::for($ability, $subject, $decision->reason);
        }

        if ($decision->needsApproval()) {
            $this->refuse($actor, $ability, $subject, 'approval_unavailable', $decision->reason);

            throw new ApprovalUnavailable(sprintf('[%s] on [%s] needs approval, which this release cannot hold.', $ability->value, $subject->store));
        }

        if ($this->needsVerification($decision, $ability, $subject) && ! $this->verifier()->isVerified($actor, $subject)) {
            $this->refuse($actor, $ability, $subject, 'verification_required', null);

            throw VerificationRequired::for($ability, $subject);
        }
    }

    /** @throws ActorRequired */
    public function assertActorNotRequired(Ability $ability, Subject $subject): void
    {
        if (! $this->requiresActor()) {
            return;
        }

        $this->refuse(null, $ability, $subject, 'actor_required', null);

        throw new ActorRequired(sprintf('[%s] on [%s] needs an actor: call as($user) or asSystem().', $ability->value, $subject->store));
    }

    private function verifier(): Verifier
    {
        return $this->runtime?->verifier() ?? $this->verifier;
    }

    private function needsVerification(Decision $decision, Ability $ability, Subject $subject): bool
    {
        if ($decision->needsVerification()) {
            return true;
        }

        if ($ability->isRead()) {
            return false;
        }

        return $subject->requiresVerification || in_array($ability, $this->verificationAbilities, true);
    }

    private function refuse(?Actor $actor, Ability $ability, Subject $subject, string $outcome, ?string $reason): void
    {
        $this->logger->warning(sprintf('laranail/settings refused [%s]: %s', $ability->value, $outcome), [
            'store'  => $subject->store->value,
            'key'    => $subject->key?->value,
            'scope'  => $subject->scope()->value,
            'actor'  => $actor?->toArray(),
            'reason' => $reason,
        ]);

        $this->auditor->record(
            AuditAction::fromAbility($ability),
            AuditOutcome::Denied,
            $subject,
            $actor,
            meta: ['refusal' => $outcome, 'detail' => $reason],
        );

        // At once, not after commit: the refusal throws, so a surrounding transaction is about to
        // roll back, and whoever listens for refusals must hear of this one regardless.
        $this->events?->dispatch($outcome === 'verification_required' && $actor instanceof Actor
            ? new VerificationWasRequired($ability, $subject, $actor)
            : new AccessWasDenied($ability, $subject, $actor, $outcome, $reason));
    }
}
