<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\Response;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Requests\ReasonRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Http\Resources\DefinitionResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Requests\SaveDefinitionRequest;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteDefinition;
use Simtabi\Laranail\Settings\Http\Requests\SaveConfigurationRequest;
use Simtabi\Laranail\Settings\Http\Resources\ConfigurationEntryResource;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\ListConfigurationEntries;

/**
 * `/configuration/{stores|layers}/{name}` and `/stores/{store}/definitions/{key}`: persisted
 * configuration, edited as the actor.
 */
final readonly class ConfigurationController
{
    public function index(PageRequest $request, ListConfigurationEntries $list, ApiActor $actors): AnonymousResourceCollection
    {
        return ConfigurationEntryResource::collection($list->handle($actors->of($request)))->additional(['meta' => ['next_cursor' => null]]);
    }

    public function update(SaveConfigurationRequest $request, SaveConfigurationEntry $save, ApiActor $actors): ConfigurationEntryResource
    {
        $entry = $request->toData();
        $save->handle($entry, $actors->of($request));

        return ConfigurationEntryResource::make($entry);
    }

    public function destroy(ReasonRequest $request, DeleteConfigurationEntry $delete, ApiActor $actors): Response
    {
        $delete->handle(new ConfigurationEntry(rtrim((string) $request->route('kind'), 's'), (string) $request->route('name')), $actors->of($request));

        return new Response(status: 204);
    }

    public function updateDefinition(SaveDefinitionRequest $request, SaveDefinition $save, SettingTargets $targets, ApiActor $actors): DefinitionResource
    {
        $definition = $save->handle($targets->store((string) $request->route('store')), (string) $request->route('key'), $request->definition(), $actors->of($request));

        return DefinitionResource::make($definition);
    }

    public function destroyDefinition(ReasonRequest $request, DeleteDefinition $delete, SettingTargets $targets, ApiActor $actors): Response
    {
        $delete->handle($targets->store((string) $request->route('store')), (string) $request->route('key'), $actors->of($request));

        return new Response(status: 204);
    }
}
