<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\SettingWrite;
use Simtabi\Laranail\Settings\Actions\SetSetting;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Actions\LockSetting;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Actions\ForgetSetting;
use Simtabi\Laranail\Settings\Actions\UnlockSetting;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Support\Addresses;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSetting;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSettings;
use Simtabi\Laranail\Settings\Actions\Reads\ExplainSetting;
use Simtabi\Laranail\Settings\Http\Requests\SettingRequest;
use Simtabi\Laranail\Settings\Http\Resources\SettingResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;
use Simtabi\Laranail\Settings\Http\Requests\WriteSettingRequest;
use Simtabi\Laranail\Settings\Http\Resources\ExplanationResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Resources\ChangeRequestResource;

/**
 * `/stores/{store}/settings` and `/stores/{store}/owners/{alias}/{id}/settings` (§16.10): resolve
 * the actor and the address, delegate to the action the PHP API uses, render. A write needing
 * approval answers 202 with the change request; `If-Match` makes a write conditional on the ETag.
 */
final readonly class SettingsController
{
    public function index(PageRequest $request, ReadSettings $readMany, Addresses $addresses, ApiActor $actors): AnonymousResourceCollection
    {
        $page = $request->toData();
        $result = $readMany->handle($addresses->of($request->addressData()), $actors->of($request), $page->limit, $page->cursor);

        return SettingResource::collection($result['items'])->additional(['meta' => ['next_cursor' => $result['next']]]);
    }

    public function show(SettingRequest $request, ReadSetting $read, Addresses $addresses, ApiActor $actors): JsonResponse
    {
        $view = $read->handle($addresses->of($request->toData()), $actors->of($request));

        return SettingResource::make($view)->response()->header('ETag', $view->etag());
    }

    public function explain(SettingRequest $request, ExplainSetting $explain, Addresses $addresses, ApiActor $actors): ExplanationResource
    {
        return ExplanationResource::make($explain->handle($addresses->of($request->toData()), $actors->of($request)));
    }

    public function update(WriteSettingRequest $request, SetSetting $set, ReadSetting $read, Addresses $addresses, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $input = $request->toData();
        $actor = $actors->of($request, $input->reason);
        $address = $addresses->of($input->address);
        $expected = $this->expectedVersion($targets, $address, $input->ifMatch);
        $change = $set->handle(new SettingWrite($targets->target($address, $input->reason), $input->value, $expected), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : $this->render($read, $address, $actor);
    }

    public function destroy(WriteSettingRequest $request, ForgetSetting $forget, Addresses $addresses, SettingTargets $targets, ApiActor $actors): JsonResponse|Response
    {
        $input = $request->toData();
        $actor = $actors->of($request, $input->reason);
        $address = $addresses->of($input->address);
        $this->expectedVersion($targets, $address, $input->ifMatch);
        $change = $forget->handle($targets->target($address, $input->reason), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : new Response(status: 204);
    }

    public function lock(WriteSettingRequest $request, LockSetting $lock, ReadSetting $read, Addresses $addresses, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $input = $request->toData();
        $actor = $actors->of($request, $input->reason);
        $address = $addresses->of($input->address);
        $change = $lock->handle($targets->target($address, $input->reason), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : $this->render($read, $address, $actor);
    }

    public function unlock(WriteSettingRequest $request, UnlockSetting $unlock, ReadSetting $read, Addresses $addresses, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $input = $request->toData();
        $actor = $actors->of($request, $input->reason);
        $address = $addresses->of($input->address);
        $change = $unlock->handle($targets->target($address, $input->reason), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : $this->render($read, $address, $actor);
    }

    /**
     * `If-Match` against the address's current ETag: a mismatch is 412, a match makes the write
     * conditional on that version (the version-checked update, §6). `*` matches any version.
     *
     * @throws ConcurrentModification
     */
    private function expectedVersion(SettingTargets $targets, SettingAddress $address, ?string $ifMatch): ?int
    {
        if ($ifMatch === null || $ifMatch === '*') {
            return null;
        }

        $version = $targets->version($address);

        if (! in_array($address->etag($version), array_map(trim(...), explode(',', $ifMatch)), true)) {
            throw new ConcurrentModification(sprintf('Setting [%s] in store [%s] changed since it was read: its ETag no longer matches If-Match.', $address->key, $address->store));
        }

        return $version;
    }

    private function render(ReadSetting $read, SettingAddress $address, Actor $actor): JsonResponse
    {
        $view = $read->handle($address, $actor);

        return SettingResource::make($view)->response()->header('ETag', $view->etag());
    }

    private function accepted(ChangeRequest $change): JsonResponse
    {
        return ChangeRequestResource::make($change)->response()->setStatusCode(202);
    }
}
