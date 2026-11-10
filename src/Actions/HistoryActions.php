<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

/**
 * The history actions a settings handle delegates to (§4.3), held together so a handle is built
 * from one collaborator rather than three.
 */
final readonly class HistoryActions
{
    public function __construct(
        public QueryHistory $query,
        public RollbackSetting $rollback,
        public DiffHistory $diff,
    ) {}
}
