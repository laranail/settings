<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Data\ChangeDecision;
use Simtabi\Laranail\Settings\Data\ChangeProposal;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Data\Http\AddressInput;
use Simtabi\Laranail\Settings\Http\Support\Addresses;
use Simtabi\Laranail\Settings\Http\Requests\BodyRequest;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Actions\Approvals\CancelChange;
use Simtabi\Laranail\Settings\Actions\Approvals\QueryChanges;
use Simtabi\Laranail\Settings\Actions\Approvals\RejectChange;
use Simtabi\Laranail\Settings\Actions\Approvals\ApproveChange;
use Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Resources\ChangeRequestResource;

/**
 * `/stores/{store}/changes` (§16.7): list, read, propose, approve, reject and cancel change
 * requests, as the PHP API does. A proposal answers 202.
 */
final readonly class ChangesController
{
    public function __construct(
        private ApiActor $actors,
        private Addresses $addresses,
        private SettingTargets $targets,
        private QueryChanges $query,
        private ProposeChange $propose,
        private ApproveChange $approve,
        private RejectChange $reject,
        private CancelChange $cancel,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        $page = $request->toData();
        $store = $this->targets->store($request->addressData()->store);
        $status = $page->filter('status');
        $key = $page->filter('key');
        $filter = new ChangeFilter(
            $store->name,
            $status === null ? null : (ChangeStatus::tryFrom($status) ?? throw new InvalidIdentifier(sprintf('status: [%s] is not a change status.', $status))),
            key: $key === null ? null : new Key($key),
        );
        $requests = $this->query->handle($filter, $this->actors->of($request), $page->limit + 1, $page->cursor);
        $next = count($requests) > $page->limit ? $requests[$page->limit - 1]->id : null;

        return ListResponse::make(array_map(static fn (ChangeRequest $change): array => $change->toArray(), array_slice($requests, 0, $page->limit)), $next);
    }

    public function show(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $store = $this->targets->store($input->param('store'));
        $found = $this->query->handle(new ChangeFilter($store->name, id: $input->param('id')), $this->actors->of($request), 1);

        return $this->render($found[0] ?? throw new UnknownChangeRequest(sprintf('Store [%s] has no change request [%s].', $store->name, $input->param('id'))));
    }

    public function store(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $owner = $input->array('owner');
        $address = $this->addresses->of(new AddressInput(
            $input->param('store'),
            (string) $input->text('key'),
            is_string($owner['alias'] ?? null) ? $owner['alias'] : null,
            is_string($owner['id'] ?? null) ? $owner['id'] : null,
            $input->text('locale') ?? '',
        ));
        $operation = ChangeOperation::tryFrom($input->text('operation') ?? 'set') ?? ChangeOperation::Set;
        $change = $this->propose->handle(new ChangeProposal($this->targets->target($address, $input->text('reason')), $operation, $input->value('value')), $this->actors->of($request, $input->text('reason')));

        return $this->render($change, 202);
    }

    public function approve(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();

        return $this->render($this->approve->handle($this->decision($input->param('store'), $input->param('id'), $input->text('reason')), $this->actors->of($request)));
    }

    public function reject(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();

        return $this->render($this->reject->handle($this->decision($input->param('store'), $input->param('id'), $input->text('reason')), $this->actors->of($request)));
    }

    public function cancel(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();

        return $this->render($this->cancel->handle($this->decision($input->param('store'), $input->param('id'), $input->text('reason')), $this->actors->of($request)));
    }

    private function decision(string $store, string $id, ?string $reason): ChangeDecision
    {
        return new ChangeDecision($this->targets->store($store), $id, $reason);
    }

    private function render(ChangeRequest $change, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => new ChangeRequestResource($change)->resolve()], $status);
    }
}
