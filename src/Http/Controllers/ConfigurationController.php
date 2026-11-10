<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Http\Requests\BodyRequest;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Http\Resources\DefinitionResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\ListConfigurationEntries;

/**
 * `/configuration/{stores|layers}/{name}` and `/configuration/definitions/{store}/{key}` (§16.11):
 * persisted configuration, edited as the actor, validated like the configuration file.
 */
final readonly class ConfigurationController
{
    public function __construct(
        private ApiActor $actors,
        private SettingTargets $targets,
        private ListConfigurationEntries $list,
        private SaveConfigurationEntry $save,
        private DeleteConfigurationEntry $delete,
        private SaveDefinition $saveDefinition,
        private DeleteDefinition $deleteDefinition,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        return ListResponse::make(array_map(static fn (ConfigurationEntry $entry): array => ['kind' => $entry->kind, 'name' => $entry->name, 'config' => (object) $entry->config], $this->list->handle($this->actors->of($request))));
    }

    public function update(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $entry = new ConfigurationEntry(rtrim($input->param('kind'), 's'), $input->param('name'), $input->array('config'));
        $this->save->handle($entry, $this->actors->of($request));

        return new JsonResponse(['data' => ['kind' => $entry->kind, 'name' => $entry->name, 'config' => (object) $entry->config]]);
    }

    public function destroy(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $this->delete->handle(new ConfigurationEntry(rtrim($input->param('kind'), 's'), $input->param('name')), $this->actors->of($request));

        return new JsonResponse(null, 204);
    }

    public function updateDefinition(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $definition = $this->saveDefinition->handle($this->targets->store($input->param('store')), $input->param('key'), $input->array('attributes'), $this->actors->of($request));

        return new JsonResponse(['data' => DefinitionResource::fields($definition)]);
    }

    public function destroyDefinition(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $this->deleteDefinition->handle($this->targets->store($input->param('store')), $input->param('key'), $this->actors->of($request));

        return new JsonResponse(null, 204);
    }
}
