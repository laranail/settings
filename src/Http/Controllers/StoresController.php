<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Actions\Reads\ListStores;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Http\Resources\StoreResource;
use Simtabi\Laranail\Settings\Actions\Reads\ListDefinitions;
use Simtabi\Laranail\Settings\Http\Resources\DefinitionResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/** `/stores` and `/stores/{store}/definitions`: what an API client can read and write. */
final readonly class StoresController
{
    public function __construct(
        private ApiActor $actors,
        private SettingTargets $targets,
        private ListStores $stores,
        private ListDefinitions $definitions,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        return ListResponse::make(array_map(StoreResource::fields(...), $this->stores->handle($this->actors->of($request))));
    }

    public function definitions(PageRequest $request): JsonResponse
    {
        $definitions = $this->definitions->handle($this->targets->store($request->addressData()->store), $this->actors->of($request));

        return ListResponse::make(array_map(DefinitionResource::fields(...), $definitions));
    }
}
