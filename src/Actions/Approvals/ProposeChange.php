<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Data\ApprovalPolicy;
use Simtabi\Laranail\Settings\Data\ChangeProposal;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Events\ChangeRequested;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Open a change request; needs the ability of the write it proposes. The value is validated and
 * encoded now, as a write would store it, so it is encrypted at rest and refused early when invalid.
 */
final readonly class ProposeChange
{
    public function __construct(
        private AccessGuard $access,
        private ValuePipeline $pipeline,
        private Dispatcher $events,
        private ApprovalPolicy $policy = new ApprovalPolicy,
    ) {}

    /**
     * @throws ApprovalUnavailable when the store cannot hold change requests
     * @throws InvalidSettingValue
     */
    public function handle(ChangeProposal $proposal, ?Actor $actor = null): ChangeRequest
    {
        $target = $proposal->target;
        $store = $target->store;
        $subject = $target->subject();

        $this->access->authorize($actor, $proposal->operation->ability(), $subject);
        $database = $store->changeRequestsDatabase();

        if (! $target->definition instanceof Definition && $store->strict) {
            throw new UnknownSetting(sprintf('Store [%s] is strict and has no definition for [%s].', $store->name, $target->key));
        }

        if ($target->owner instanceof OwnerRef && ! $store->accepts($target->owner->alias)) {
            throw new OwnerNotAllowed(sprintf('Store [%s] does not accept owner alias [%s].', $store->name, $target->owner->alias));
        }

        $scope = $subject->scope();
        $payload = $proposal->operation === ChangeOperation::Set ? $this->pipeline->toPayload($target->key, $proposal->value, $target->definition) : null;
        $base = $store->writableLayer()->driver->load($store->name, [$scope])->find($scope, $target->key, $target->locale)->version ?? 0;
        $now = CarbonImmutable::now();
        $requester = $actor ?? Actor::system($target->reason);

        $request = $store->transaction(function () use ($database, $store, $scope, $target, $proposal, $payload, $base, $now, $requester, $subject, $actor): ChangeRequest {
            $request = $database->attempt($store->name, fn (): ChangeRequest => SettingChangeRequest::open($database->connectionName(), [
                'store'        => $store->name->value,
                'scope'        => $scope->value,
                'key'          => $target->key->value,
                'locale'       => $target->locale->value,
                'operation'    => $proposal->operation,
                'payload'      => $payload,
                'type'         => $target->definition?->type,
                'encrypted'    => $payload !== null && $target->definition instanceof Definition && $target->definition->encrypted,
                'base_version' => $base,
                'reason'       => $target->reason,
                'expires_at'   => $this->policy->expireAfterDays === null ? null : $now->addDays($this->policy->expireAfterDays),
            ], $requester, $now));

            $this->access->auditor()->record(AuditAction::Propose, AuditOutcome::Pending, $subject, $actor, $target->reason, versionFrom: $base > 0 ? $base : null, meta: [
                'change_request' => $request->id,
                'operation'      => $proposal->operation->value,
            ]);

            return $request;
        });

        $this->events->dispatch(new ChangeRequested($request->id, $request->store, $request->scope, $request->key, $request->locale, $request->operation->value));

        return $request;
    }
}
