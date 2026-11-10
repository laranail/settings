<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

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
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSettings;
use Simtabi\Laranail\Settings\Actions\Reads\ExplainSetting;
use Simtabi\Laranail\Settings\Http\Requests\SettingRequest;
use Simtabi\Laranail\Settings\Http\Resources\SettingResource;
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
    public function __construct(
        private ApiActor $actors,
        private Addresses $addresses,
        private SettingTargets $targets,
        private ReadSetting $read,
        private ReadSettings $readMany,
        private ExplainSetting $explain,
        private SetSetting $set,
        private ForgetSetting $forget,
        private LockSetting $lock,
        private UnlockSetting $unlock,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        $page = $request->toData();
        $result = $this->readMany->handle($this->addresses->of($request->addressData()), $this->actors->of($request), $page->limit, $page->cursor);

        return ListResponse::make(array_map(SettingResource::fields(...), $result['items']), $result['next']);
    }

    public function show(SettingRequest $request): JsonResponse
    {
        $view = $this->read->handle($this->addresses->of($request->toData()), $this->actors->of($request));

        return new JsonResponse(['data' => SettingResource::fields($view)], 200, ['ETag' => $view->etag()]);
    }

    public function explain(SettingRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ExplanationResource::fields($this->explain->handle($this->addresses->of($request->toData()), $this->actors->of($request)))]);
    }

    public function update(WriteSettingRequest $request): JsonResponse
    {
        $input = $request->toData();
        $actor = $this->actors->of($request, $input->reason);
        $address = $this->addresses->of($input->address);
        $expected = $this->expectedVersion($address, $input->ifMatch);
        $change = $this->set->handle(new SettingWrite($this->targets->target($address, $input->reason), $input->value, $expected), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : $this->render($address, $actor);
    }

    public function destroy(WriteSettingRequest $request): JsonResponse
    {
        $input = $request->toData();
        $actor = $this->actors->of($request, $input->reason);
        $address = $this->addresses->of($input->address);
        $this->expectedVersion($address, $input->ifMatch);
        $change = $this->forget->handle($this->targets->target($address, $input->reason), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : new JsonResponse(null, 204);
    }

    public function lock(WriteSettingRequest $request): JsonResponse
    {
        $input = $request->toData();
        $actor = $this->actors->of($request, $input->reason);
        $address = $this->addresses->of($input->address);
        $change = $this->lock->handle($this->targets->target($address, $input->reason), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : $this->render($address, $actor);
    }

    public function unlock(WriteSettingRequest $request): JsonResponse
    {
        $input = $request->toData();
        $actor = $this->actors->of($request, $input->reason);
        $address = $this->addresses->of($input->address);
        $change = $this->unlock->handle($this->targets->target($address, $input->reason), $actor);

        return $change instanceof ChangeRequest ? $this->accepted($change) : $this->render($address, $actor);
    }

    /**
     * `If-Match` against the address's current ETag: a mismatch is 412, a match makes the write
     * conditional on that version (the version-checked update, §6). `*` matches any version.
     *
     * @throws ConcurrentModification
     */
    private function expectedVersion(SettingAddress $address, ?string $ifMatch): ?int
    {
        if ($ifMatch === null || $ifMatch === '*') {
            return null;
        }

        $version = $this->targets->version($address);

        if (! in_array($address->etag($version), array_map(trim(...), explode(',', $ifMatch)), true)) {
            throw new ConcurrentModification(sprintf('Setting [%s] in store [%s] changed since it was read: its ETag no longer matches If-Match.', $address->key, $address->store));
        }

        return $version;
    }

    private function render(SettingAddress $address, Actor $actor): JsonResponse
    {
        $view = $this->read->handle($address, $actor);

        return new JsonResponse(['data' => SettingResource::fields($view)], 200, ['ETag' => $view->etag()]);
    }

    private function accepted(ChangeRequest $change): JsonResponse
    {
        return new JsonResponse(['data' => new ChangeRequestResource($change)->resolve()], 202);
    }
}
