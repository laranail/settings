<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Requests\BodyRequest;
use Simtabi\Laranail\Settings\Actions\Transfer\DiffStore;
use Simtabi\Laranail\Settings\Data\Transfer\ExportRequest;
use Simtabi\Laranail\Settings\Data\Transfer\ImportRequest;
use Simtabi\Laranail\Settings\Actions\Transfer\ExportStore;
use Simtabi\Laranail\Settings\Actions\Transfer\ImportStore;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/**
 * `/stores/{store}/export`, `/import` and `/diff` (G-42): the same document the console writes.
 * An import is a dry run unless `apply` is true, and is never trusted over HTTP (locks and system
 * categories in the document are ignored; that is the console's `--trusted`).
 */
final readonly class TransferController
{
    public function __construct(
        private ApiActor $actors,
        private SettingTargets $targets,
        private ExportStore $export,
        private ImportStore $import,
        private DiffStore $diff,
    ) {}

    public function export(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $mode = ExportMode::tryFrom($input->text('mode') ?? 'redacted') ?? ExportMode::Redacted;
        $document = $this->export->handle(new ExportRequest($this->targets->store($input->param('store')), $mode), $this->actors->of($request));

        return new JsonResponse(['data' => $document->toArray()]);
    }

    public function import(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $report = $this->import->handle(new ImportRequest(
            $this->targets->store($input->param('store')),
            ExportDocument::fromArray($input->array('document')),
            apply: $input->boolean('apply'),
        ), $this->actors->of($request));

        return new JsonResponse(['data' => $report->toArray()]);
    }

    public function diff(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $diff = $this->diff->handle($this->targets->store($input->param('store')), ExportDocument::fromArray($input->array('document')), $this->actors->of($request));

        return new JsonResponse(['data' => $diff->toArray()]);
    }
}
