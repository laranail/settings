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
use Simtabi\Laranail\Settings\Events\ChangeRejected;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;
use Simtabi\Laranail\Settings\Services\Database\ChangeRequestRepository;

/** Reject a change request; needs `approve` on its setting. */
final readonly class RejectChange
{
    public function __construct(
        private AccessGuard $access,
        private Dispatcher $events,
        private Definitions $definitions,
    ) {}

    /** @throws ChangeRequestClosed */
    public function handle(ChangeDecision $decision, ?Actor $actor = null): ChangeRequest
    {
        $store = $decision->store;
        $repository = ChangeRequestRepository::of($store);
        $request = $repository->get($store->name, $decision->id);
        $key = new Key($request->key);
        $subject = Subject::of($store->name, $key, new Locale($request->locale), $request->owner(), $this->definitions->find($store, $key));

        $this->access->authorize($actor, Ability::Approve, $subject);

        $store->transaction(function () use ($repository, $request, $actor, $decision, $subject): void {
            if (! $repository->transition($request, [ChangeStatus::Pending, ChangeStatus::Approved], ChangeStatus::Rejected, $actor ?? Actor::system(), $decision->reason, CarbonImmutable::now())) {
                throw new ChangeRequestClosed(sprintf('Change request [%s] is %s and can no longer be decided.', $request->id, $request->status->value));
            }

            $this->access->auditor()->record(AuditAction::Reject, AuditOutcome::Allowed, $subject, $actor, $decision->reason, meta: ['change_request' => $request->id]);
        });

        $this->events->dispatch(new ChangeRejected($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value));

        return $repository->get($store->name, $request->id);
    }
}
