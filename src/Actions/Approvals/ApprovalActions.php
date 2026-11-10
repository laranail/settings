<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

/**
 * The approval actions an entry point delegates to (§16.7), held together so a handle is built
 * from one collaborator rather than six.
 */
final readonly class ApprovalActions
{
    public function __construct(
        public ProposeChange $propose,
        public ApproveChange $approve,
        public RejectChange $reject,
        public CancelChange $cancel,
        public QueryChanges $query,
        public PruneChanges $prune,
    ) {}
}
