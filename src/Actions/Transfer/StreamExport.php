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
use Simtabi\Laranail\Settings\Services\Transfer\StoreSnapshots;

/**
 * Write a store's export to a stream, 500 rows at a time: the bytes {@see ExportStore}'s document
 * gives from `toJson()`, without holding the store in memory. Authorized as {@see ExportStore} is,
 * before anything is written.
 */
final readonly class StreamExport
{
    public function __construct(
        private AccessGuard $access,
        private StoreSnapshots $snapshots,
    ) {}

    /**
     * @param resource $stream
     *
     * @return array{settings: int, categories: int}
     */
    public function handle(ExportRequest $request, $stream, ?Actor $actor = null): array
    {
        $subject = new Subject($request->store->name);

        $this->access->authorize($actor, Ability::Export, $subject);

        if ($request->mode === ExportMode::Plaintext) {
            $this->access->authorize($actor, Ability::ViewSensitive, $subject);
        }

        $counts = $this->snapshots->exportTo($request->store, $request->mode, $stream);
        $this->access->auditor()->record(AuditAction::Export, AuditOutcome::Allowed, $subject, $actor, meta: ['mode' => $request->mode->value, 'settings' => $counts['settings'], 'categories' => $counts['categories']]);

        return $counts;
    }
}
