<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Data\ChangeDecision;
use Simtabi\Laranail\Settings\Data\ChangeProposal;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Support\Addresses;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Requests\ReasonRequest;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Actions\Approvals\CancelChange;
use Simtabi\Laranail\Settings\Actions\Approvals\QueryChanges;
use Simtabi\Laranail\Settings\Actions\Approvals\RejectChange;
use Simtabi\Laranail\Settings\Actions\Approvals\ApproveChange;
use Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Http\Requests\ProposeChangeRequest;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Resources\ChangeRequestResource;

/**
 * `/stores/{store}/changes` (§16.7): list, read, propose, approve, reject and cancel change
 * requests, as the PHP API does. A proposal answers 202.
 */
final readonly class ChangesController
{
    public function index(PageRequest $request, QueryChanges $query, SettingTargets $targets, ApiActor $actors): AnonymousResourceCollection
    {
        $page = $request->toData();
        $store = $targets->store($request->addressData()->store);
        $status = $page->filter('status');
        $key = $page->filter('key');
        $filter = new ChangeFilter(
            $store->name,
            $status === null ? null : (ChangeStatus::tryFrom($status) ?? throw new InvalidIdentifier(sprintf('status: [%s] is not a change status.', $status))),
            key: $key === null ? null : new Key($key),
        );
        $requests = $query->handle($filter, $actors->of($request), $page->limit + 1, $page->cursor);
        $next = count($requests) > $page->limit ? $requests[$page->limit - 1]->id : null;

        return ChangeRequestResource::collection(array_slice($requests, 0, $page->limit))->additional(['meta' => ['next_cursor' => $next]]);
    }

    public function show(ReasonRequest $request, QueryChanges $query, SettingTargets $targets, ApiActor $actors): ChangeRequestResource
    {
        $store = $targets->store((string) $request->route('store'));
        $id = (string) $request->route('id');
        $found = $query->handle(new ChangeFilter($store->name, id: $id), $actors->of($request), 1);

        return ChangeRequestResource::make($found[0] ?? throw new UnknownChangeRequest(sprintf('Store [%s] has no change request [%s].', $store->name, $id)));
    }

    public function store(ProposeChangeRequest $request, ProposeChange $propose, Addresses $addresses, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $target = $targets->target($addresses->of($request->toData()), $request->reason());
        $change = $propose->handle(new ChangeProposal($target, $request->operation(), $request->input('value')), $actors->of($request, $request->reason()));

        return ChangeRequestResource::make($change)->response()->setStatusCode(202);
    }

    public function approve(ReasonRequest $request, ApproveChange $approve, SettingTargets $targets, ApiActor $actors): ChangeRequestResource
    {
        return ChangeRequestResource::make($approve->handle($this->decision($request, $targets), $actors->of($request)));
    }

    public function reject(ReasonRequest $request, RejectChange $reject, SettingTargets $targets, ApiActor $actors): ChangeRequestResource
    {
        return ChangeRequestResource::make($reject->handle($this->decision($request, $targets), $actors->of($request)));
    }

    public function cancel(ReasonRequest $request, CancelChange $cancel, SettingTargets $targets, ApiActor $actors): ChangeRequestResource
    {
        return ChangeRequestResource::make($cancel->handle($this->decision($request, $targets), $actors->of($request)));
    }

    private function decision(ReasonRequest $request, SettingTargets $targets): ChangeDecision
    {
        return new ChangeDecision($targets->store((string) $request->route('store')), (string) $request->route('id'), $request->reason());
    }
}
