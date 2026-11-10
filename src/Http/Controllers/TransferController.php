<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Actions\Transfer\DiffStore;
use Simtabi\Laranail\Settings\Data\Transfer\ExportRequest;
use Simtabi\Laranail\Settings\Data\Transfer\ImportRequest;
use Simtabi\Laranail\Settings\Actions\Transfer\ExportStore;
use Simtabi\Laranail\Settings\Actions\Transfer\ImportStore;
use Simtabi\Laranail\Settings\Http\Requests\DiffStoreRequest;
use Simtabi\Laranail\Settings\Http\Requests\ExportStoreRequest;
use Simtabi\Laranail\Settings\Http\Requests\ImportStoreRequest;
use Simtabi\Laranail\Settings\Http\Resources\StoreDiffResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Resources\ImportReportResource;
use Simtabi\Laranail\Settings\Http\Resources\ExportDocumentResource;

/**
 * `/stores/{store}/export`, `/import` and `/diff`. An import is a dry run unless `apply` is true,
 * and is never trusted over HTTP.
 */
final readonly class TransferController
{
    public function export(ExportStoreRequest $request, ExportStore $export, SettingTargets $targets, ApiActor $actors): ExportDocumentResource
    {
        $document = $export->handle(new ExportRequest($targets->store((string) $request->route('store')), $request->mode()), $actors->of($request));

        return ExportDocumentResource::make($document);
    }

    public function import(ImportStoreRequest $request, ImportStore $import, SettingTargets $targets, ApiActor $actors): ImportReportResource
    {
        $report = $import->handle(new ImportRequest(
            $targets->store((string) $request->route('store')),
            $request->document(),
            apply: $request->boolean('apply'),
        ), $actors->of($request));

        return ImportReportResource::make($report);
    }

    public function diff(DiffStoreRequest $request, DiffStore $diff, SettingTargets $targets, ApiActor $actors): StoreDiffResource
    {
        $result = $diff->handle($targets->store((string) $request->route('store')), $request->document(), $actors->of($request));

        return StoreDiffResource::make($result);
    }
}
