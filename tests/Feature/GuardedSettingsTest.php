<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Verification\PasswordConfirmationVerifier;

function defineSetting(): DefinitionBuilder
{
    return app(DefinitionRegistry::class)->define('mail.api_key');
}

/** Bind a request with an array session, so password confirmation can be read from it. */
function withSession(?int $confirmedAt): void
{
    $request = Request::create('/');
    $session = app('session')->driver('array');
    $request->setLaravelSession($session);

    if ($confirmedAt !== null) {
        $session->put(PasswordConfirmationVerifier::SESSION_KEY, $confirmedAt);
    }

    app()->instance('request', $request);
}

describe('sensitive reads', function (): void {
    it('needs view_sensitive to read a sensitive setting', function (): void {
        defineSetting()->sensitive()->register();
        settings()->set('mail.api_key', TestCredentials::secret());
        allowGates(Ability::View);

        expect(fn (): mixed => settings()->as(user())->get('mail.api_key'))->toThrow(AccessDenied::class, '[view_sensitive]');

        allowGates(Ability::ViewSensitive);

        expect(settings()->as(user())->get('mail.api_key'))->toBe(TestCredentials::secret());
    });

    it('treats an encrypted definition as sensitive', function (): void {
        defineSetting()->encrypted()->register();
        allowGates(Ability::View);

        expect(fn (): Lookup => settings()->as(user())->lookup('mail.api_key'))->toThrow(AccessDenied::class, '[view_sensitive]');
    });

    it('redacts the value in explain() instead of refusing, keeping the path', function (): void {
        defineSetting()->sensitive()->register();
        settings()->set('mail.api_key', TestCredentials::secret());
        allowGates(Ability::View);

        $explanation = settings()->as(user())->explain('mail.api_key');

        expect($explanation->isRedacted())->toBeTrue()
            ->and($explanation->lookup->isFound())->toBeTrue()
            ->and($explanation->lookup->layer())->toBe('runtime')
            ->and($explanation->lookup->valueOr('hidden'))->toBe('hidden')
            ->and($explanation->steps)->not->toBeEmpty()
            ->and(fn (): mixed => $explanation->lookup->value())->toThrow(LogicException::class, 'redacted');
    });

    it('does not redact for an actor allowed to view sensitive settings, or for trusted code', function (): void {
        defineSetting()->sensitive()->register();
        settings()->set('mail.api_key', TestCredentials::secret());
        allowGates(Ability::View, Ability::ViewSensitive);

        expect(settings()->as(user())->explain('mail.api_key')->isRedacted())->toBeFalse()
            ->and(settings()->explain('mail.api_key')->lookup->value())->toBe(TestCredentials::secret());
    });

    it('never redacts for an explicit system actor', function (): void {
        defineSetting()->sensitive()->register();
        settings()->set('mail.api_key', TestCredentials::secret());

        expect(settings()->asSystem()->explain('mail.api_key')->isRedacted())->toBeFalse();
    });

    it('refuses explain() entirely without view', function (): void {
        expect(fn (): Explanation => settings()->as(user())->explain('site.name'))->toThrow(AccessDenied::class, '[view]');
    });

    it('reads a sensitive flag from config definitions', function (): void {
        config()->set('laranail.settings.definitions', ['mail.api_key' => ['sensitive' => true, 'requires_verification' => true, 'requires_approval' => true]]);

        $definition = app(DefinitionRegistry::class)->find(new StoreName('settings'), new Key('mail.api_key'));

        expect($definition?->isSensitive())->toBeTrue()
            ->and($definition?->requiresVerification)->toBeTrue()
            ->and($definition?->requiresApproval)->toBeTrue();
    });
});

describe('guarded locks', function (): void {
    it('needs lock to lock and unlock to unlock', function (): void {
        allowGates(Ability::Lock);

        settings()->as(user())->lock('site.name');

        expect(fn () => settings()->as(user())->unlock('site.name'))->toThrow(AccessDenied::class, '[unlock]');

        allowGates(Ability::Unlock);
        settings()->as(user())->unlock('site.name');
        settings()->set('site.name', 'open again');

        expect(settings()->get('site.name'))->toBe('open again');
    });

    it('refuses a locked write when the store does not allow overrides, even with override_lock', function (): void {
        allowGates(Ability::Update, Ability::OverrideLock);
        settings()->set('site.name', 'Acme');
        settings()->lock('site.name');

        expect(fn () => settings()->as(user())->set('site.name', 'x'))->toThrow(LockedSetting::class)
            ->and(fn () => settings()->set('site.name', 'x'))->toThrow(LockedSetting::class);
    });

    it('refuses a locked write on an overridable store to an actor without override_lock', function (): void {
        config()->set('laranail.settings.stores.settings.locks.overridable', true);
        allowGates(Ability::Update);
        settings()->lock('site.name');

        expect(fn () => settings()->as(user())->set('site.name', 'x'))->toThrow(LockedSetting::class)
            ->and(fn () => settings()->asSystem()->set('site.name', 'x'))->toThrow(LockedSetting::class);
    });

    it('lets a verified actor holding override_lock write a locked setting on an overridable store, keeping the lock', function (): void {
        config()->set('laranail.settings.stores.settings.locks.overridable', true);
        allowGates(Ability::Update, Ability::OverrideLock, Ability::View, Ability::Forget);
        settings()->set('site.name', 'Acme');
        settings()->lock('site.name');
        $verified = Actor::user(user(), verifiedAt: now()->toImmutable());

        settings()->as($verified)->set('site.name', 'Overridden');

        expect(settings()->get('site.name'))->toBe('Overridden')
            ->and(fn () => settings()->set('site.name', 'x'))->toThrow(LockedSetting::class);

        settings()->as($verified)->forget('site.name');

        expect(settings()->has('site.name'))->toBeFalse();
    });

    it('writes an unlocked row on an overridable store without asking for override_lock', function (): void {
        config()->set('laranail.settings.stores.settings.locks.overridable', true);
        allowGates(Ability::Update);
        $fake = Settings::fake();

        $fake->as(user())->set('site.name', 'Acme');

        expect($fake->auditEntries()[0]->meta)->toBe([])
            ->and($fake->get('site.name'))->toBe('Acme');
    });

    it('records an override in the audit entry', function (): void {
        config()->set('laranail.settings.stores.settings.locks.overridable', true);
        config()->set('laranail.settings.verification.driver', 'none');
        allowGates(Ability::Update, Ability::OverrideLock);
        $fake = Settings::fake();
        $fake->lock('site.name');

        $fake->as(user())->set('site.name', 'Acme');

        $entries = $fake->auditEntries();

        expect($entries[count($entries) - 1]->meta)->toBe(['override_lock' => true]);
    });

    it('needs step-up verification to override a lock, by default', function (): void {
        config()->set('laranail.settings.stores.settings.locks.overridable', true);
        allowGates(Ability::Update, Ability::OverrideLock);
        settings()->lock('site.name');

        expect(fn () => settings()->as(user())->set('site.name', 'x'))->toThrow(VerificationRequired::class, '[override_lock]');
    });

    it('rejects a locks entry that is not an array', function (): void {
        config()->set('laranail.settings.stores.settings.locks', true);

        expect(fn (): mixed => settings()->get('site.name'))->toThrow(InvalidConfiguration::class, 'laranail.settings.stores.settings.locks');
    });
});

describe('step-up verification', function (): void {
    // The boundary tests build "exactly 300 seconds ago" from one clock reading and the verifier
    // reads the clock again; on a slow run (coverage) a second can pass between the two.
    beforeEach(function (): void {
        $this->freezeTime();
    });

    it('requires verification for a definition marked requiresVerification', function (): void {
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update, Ability::View);

        expect(fn () => settings()->as(user())->set('mail.api_key', 'x'))->toThrow(VerificationRequired::class)
            ->and(settings()->as(user())->get('mail.api_key'))->toBeNull();
    });

    it('accepts a fresh password confirmation from the session', function (): void {
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);
        withSession(now()->getTimestamp() - 60);

        settings()->as(user())->set('mail.api_key', 'x');

        expect(settings()->get('mail.api_key'))->toBe('x');
    });

    it('rejects a password confirmation older than auth.password_timeout', function (): void {
        config()->set('auth.password_timeout', 300);
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);
        withSession(now()->getTimestamp() - 301);

        expect(fn () => settings()->as(user())->set('mail.api_key', 'x'))->toThrow(VerificationRequired::class);
    });

    it('falls back to the actor timestamp outside HTTP, and rejects a stale or future one', function (): void {
        config()->set('auth.password_timeout', 300);
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);
        withSession(null);

        settings()->as(Actor::user(user(), verifiedAt: now()->subSeconds(300)->toImmutable()))->set('mail.api_key', 'x');

        expect(fn () => settings()->as(Actor::user(user(), verifiedAt: now()->subSeconds(301)->toImmutable()))->set('mail.api_key', 'y'))->toThrow(VerificationRequired::class)
            ->and(fn () => settings()->as(Actor::user(user(), verifiedAt: now()->addMinute()->toImmutable()))->set('mail.api_key', 'y'))->toThrow(VerificationRequired::class);
    });

    it('uses the actor timestamp within verification.ttl when the actor driver is chosen', function (): void {
        config()->set('laranail.settings.verification.driver', 'actor');
        config()->set('laranail.settings.verification.ttl', 60);
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);

        settings()->as(Actor::user(user(), verifiedAt: now()->subSeconds(60)->toImmutable()))->set('mail.api_key', 'x');

        expect(fn () => settings()->as(Actor::user(user(), verifiedAt: now()->subSeconds(61)->toImmutable()))->set('mail.api_key', 'y'))->toThrow(VerificationRequired::class);
    });

    it('skips verification with the none driver', function (): void {
        config()->set('laranail.settings.verification.driver', 'none');
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);

        settings()->as(user())->set('mail.api_key', 'x');

        expect(settings()->get('mail.api_key'))->toBe('x');
    });

    it('accepts a custom verifier class and rejects an unknown driver or ability', function (): void {
        config()->set('laranail.settings.verification.driver', AlwaysVerified::class);
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);

        settings()->as(user())->set('mail.api_key', 'x');

        config()->set('laranail.settings.verification.driver', 'sms');
        app()->forgetScopedInstances();

        expect(fn () => settings()->as(user())->set('mail.api_key', 'x'))->toThrow(InvalidConfiguration::class, 'verification.driver');

        config()->set('laranail.settings.verification.driver', 'none');
        config()->set('laranail.settings.verification.abilities', ['fly']);
        app()->forgetScopedInstances();

        expect(fn () => settings()->as(user())->set('mail.api_key', 'x'))->toThrow(InvalidConfiguration::class, 'unknown ability [fly]');
    });

    it('applies verification to abilities listed in verification.abilities', function (): void {
        config()->set('laranail.settings.verification.abilities', ['update']);
        allowGates(Ability::Update);

        expect(fn () => settings()->as(user())->set('site.name', 'x'))->toThrow(VerificationRequired::class);
    });

    it('lets an authorizer demand verification for one decision', function (): void {
        settings()->configuration()->authorizeUsing(static fn (): Decision => Decision::requiresVerification());

        expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(VerificationRequired::class, '[view]');
    });

    it('audits a verification failure as a denial', function (): void {
        $fake = Settings::fake();
        defineSetting()->requiresVerification()->register();
        allowGates(Ability::Update);

        expect(fn () => $fake->as(user())->set('mail.api_key', 'x'))->toThrow(VerificationRequired::class);

        $fake->assertDenied('mail.api_key', Ability::Update);
        expect($fake->auditEntries()[0]->meta['refusal'])->toBe('verification_required');
    });
});

describe('approvals', function (): void {
    it('refuses every write to a setting that requires approval, with or without an actor', function (): void {
        defineSetting()->requiresApproval()->register();
        allowGates(Ability::Update, Ability::Lock);

        expect(fn () => settings()->set('mail.api_key', 'x'))->toThrow(ApprovalUnavailable::class, 'requires approval')
            ->and(fn () => settings()->as(user())->set('mail.api_key', 'x'))->toThrow(ApprovalUnavailable::class)
            ->and(fn () => settings()->as(user())->lock('mail.api_key'))->toThrow(ApprovalUnavailable::class)
            ->and(settings()->has('mail.api_key'))->toBeFalse();
    });

    it('denies before reporting approval when the actor may not write at all', function (): void {
        defineSetting()->requiresApproval()->register();

        expect(fn () => settings()->as(user())->set('mail.api_key', 'x'))->toThrow(AccessDenied::class);
    });

    it('audits an approval refusal as failed', function (): void {
        $fake = Settings::fake();
        defineSetting()->requiresApproval()->register();

        expect(fn () => $fake->set('mail.api_key', 'x'))->toThrow(ApprovalUnavailable::class);

        $fake->assertAudited(AuditAction::Update, AuditOutcome::Failed, 'mail.api_key');
    });
});

final class AlwaysVerified implements Verifier
{
    public function isVerified(Actor $actor, Subject $subject): bool
    {
        return true;
    }
}
