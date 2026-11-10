<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Actions\Reads\ListStores;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Resources\StoreResource;
use Simtabi\Laranail\Settings\Actions\Reads\ListDefinitions;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Http\Resources\DefinitionResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/** `/stores` and `/stores/{store}/definitions`. */
final readonly class StoresController
{
    public function index(PageRequest $request, ListStores $stores, ApiActor $actors): AnonymousResourceCollection
    {
        return StoreResource::collection($stores->handle($actors->of($request)))->additional(['meta' => ['next_cursor' => null]]);
    }

    public function definitions(PageRequest $request, ListDefinitions $definitions, SettingTargets $targets, ApiActor $actors): AnonymousResourceCollection
    {
        $list = $definitions->handle($targets->store($request->addressData()->store), $actors->of($request));

        return DefinitionResource::collection($list)->additional(['meta' => ['next_cursor' => null]]);
    }
}
