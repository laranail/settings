<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Transfer;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Data\Transfer\ExportRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Services\Transfer\StoreSnapshots;

/** Export a store's settings and categories; encrypted values are redacted unless asked for. */
final readonly class ExportStore
{
    public function __construct(
        private AccessGuard $access,
        private StoreSnapshots $snapshots,
    ) {}

    public function handle(ExportRequest $request, ?Actor $actor = null): ExportDocument
    {
        $subject = new Subject($request->store->name);

        $this->access->authorize($actor, Ability::Export, $subject);

        if ($request->mode === ExportMode::Plaintext) {
            $this->access->authorize($actor, Ability::ViewSensitive, $subject);
        }

        $document = $this->snapshots->export($request->store, $request->mode);
        $this->access->auditor()->record(AuditAction::Export, AuditOutcome::Allowed, $subject, $actor, meta: ['mode' => $request->mode->value, 'settings' => count($document->settings), 'categories' => count($document->categories)]);

        return $document;
    }
}
