<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Support\Addresses;
use Simtabi\Laranail\Settings\Actions\RollbackSetting;
use Simtabi\Laranail\Settings\Actions\Reads\ReadHistory;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSetting;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Http\Resources\SettingResource;
use Simtabi\Laranail\Settings\Http\Requests\WriteSettingRequest;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/** `…/settings/{key}/history` and `…/rollback` (§4.3): a setting's versions, and returning to one. */
final readonly class HistoryController
{
    public function __construct(
        private ApiActor $actors,
        private Addresses $addresses,
        private SettingTargets $targets,
        private ReadHistory $history,
        private RollbackSetting $rollback,
        private ReadSetting $read,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        $page = $request->toData();
        $result = $this->history->handle($this->addresses->of($request->addressData()), $this->actors->of($request), $page->limit, $page->cursor);

        return ListResponse::make(array_map(static fn (HistoryEntry $entry): array => $entry->toArray(), $result->entries), $result->nextCursor);
    }

    public function rollback(WriteSettingRequest $request): JsonResponse
    {
        $input = $request->toData();
        $actor = $this->actors->of($request, $input->reason);
        $address = $this->addresses->of($input->address);

        $this->rollback->handle($this->targets->target($address, $input->reason), $input->toVersion ?? 0, $actor);
        $view = $this->read->handle($address, $actor);

        return new JsonResponse(['data' => SettingResource::fields($view)], 200, ['ETag' => $view->etag()]);
    }
}
