<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Throwable;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Actions\Transfer\DiffStore;
use Simtabi\Laranail\Settings\Data\Transfer\ExportRequest;
use Simtabi\Laranail\Settings\Data\Transfer\ImportRequest;
use Simtabi\Laranail\Settings\Actions\Transfer\ImportStore;
use Simtabi\Laranail\Settings\Actions\Transfer\StreamExport;
use Simtabi\Laranail\Settings\Http\Requests\DiffStoreRequest;
use Simtabi\Laranail\Settings\Http\Requests\ExportStoreRequest;
use Simtabi\Laranail\Settings\Http\Requests\ImportStoreRequest;
use Simtabi\Laranail\Settings\Http\Resources\StoreDiffResource;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Resources\ImportReportResource;

/**
 * `/stores/{store}/export`, `/import` and `/diff`. An import is a dry run unless `apply` is true,
 * and is never trusted over HTTP.
 */
final readonly class TransferController
{
    /**
     * The document under `data`, streamed 500 rows at a time. It is written to a temporary stream
     * first (which spills to disk past 2 MB), so a refusal or failure is still a problem response.
     */
    public function export(ExportStoreRequest $request, StreamExport $export, SettingTargets $targets, ApiActor $actors): StreamedResponse
    {
        $buffer = fopen('php://temp', 'w+b');

        if ($buffer === false) {
            throw new DriverFailure('The export could not open a temporary stream.');
        }

        try {
            fwrite($buffer, '{"data":');
            $export->handle(new ExportRequest($targets->store((string) $request->route('store')), $request->mode()), $buffer, $actors->of($request));
            fwrite($buffer, '}');
        } catch (Throwable $exception) {
            fclose($buffer);

            throw $exception;
        }

        return new StreamedResponse(static function () use ($buffer): void {
            rewind($buffer);
            fpassthru($buffer);
            fclose($buffer);
        }, 200, ['Content-Type' => 'application/json']);
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
