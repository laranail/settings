<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Locale;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Data\ChangeDecision;
use Simtabi\Laranail\Settings\Events\ChangeCancelled;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Services\Database\ChangeRequestRepository;

/**
 * Withdraw a change request: only its requester (or trusted code) may. Nothing is written.
 */
final readonly class CancelChange
{
    public function __construct(
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    /**
     * @throws AccessDenied when the actor is not the requester
     * @throws ChangeRequestClosed
     */
    public function handle(ChangeDecision $decision, ?Actor $actor = null): ChangeRequest
    {
        $store = $decision->store;
        $repository = ChangeRequestRepository::of($store);
        $request = $repository->get($store->name, $decision->id);
        $subject = new Subject($store->name, new Key($request->key), $request->owner(), new Locale($request->locale));

        if ($actor instanceof Actor && ! $actor->isSystem() && ! $request->isRequestedBy($actor)) {
            $this->access->auditor()->record(AuditAction::Cancel, AuditOutcome::Denied, $subject, $actor, $decision->reason, meta: ['change_request' => $request->id, 'refusal' => 'not_requester']);

            throw AccessDenied::for(Ability::Update, $subject, 'only the requester can cancel a change request');
        }

        if (! $actor instanceof Actor) {
            $this->access->assertActorNotRequired(Ability::Update, $subject);
        }

        $store->transaction(function () use ($repository, $request, $actor, $decision, $subject): void {
            if (! $repository->transition($request, [ChangeStatus::Pending, ChangeStatus::Approved], ChangeStatus::Cancelled, $actor ?? Actor::system(), $decision->reason, CarbonImmutable::now())) {
                throw new ChangeRequestClosed(sprintf('Change request [%s] is %s and can no longer be cancelled.', $request->id, $request->status->value));
            }

            $this->access->auditor()->record(AuditAction::Cancel, AuditOutcome::Allowed, $subject, $actor, $decision->reason, meta: ['change_request' => $request->id]);
        });

        $this->events->dispatch(new ChangeCancelled($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value));

        return $repository->get($store->name, $request->id);
    }
}
