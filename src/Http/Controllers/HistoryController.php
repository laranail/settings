<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Support\Addresses;
use Simtabi\Laranail\Settings\Actions\RollbackSetting;
use Simtabi\Laranail\Settings\Actions\Reads\ReadHistory;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSetting;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Resources\SettingResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Resources\HistoryEntryResource;
use Simtabi\Laranail\Settings\Http\Requests\RollbackSettingRequest;

/** `…/settings/{key}/history` and `…/rollback`. */
final readonly class HistoryController
{
    public function index(PageRequest $request, ReadHistory $history, Addresses $addresses, ApiActor $actors): AnonymousResourceCollection
    {
        $page = $request->toData();
        $result = $history->handle($addresses->of($request->addressData()), $actors->of($request), $page->limit, $page->cursor);

        return HistoryEntryResource::collection($result->entries)->additional(['meta' => ['next_cursor' => $result->nextCursor]]);
    }

    public function rollback(RollbackSettingRequest $request, RollbackSetting $rollback, ReadSetting $read, Addresses $addresses, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $input = $request->toData();
        $actor = $actors->of($request, $input->reason);
        $address = $addresses->of($input->address);

        $rollback->handle($targets->target($address, $input->reason), $input->toVersion ?? 0, $actor);
        $view = $read->handle($address, $actor);

        return SettingResource::make($view)->response()->header('ETag', $view->etag());
    }
}
