<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The write needs a second person's approval, and this store cannot hold change requests: it has
 * no active database layer (a file-only store, or one skipped during bootstrap), or its schema was
 * recorded without the change requests table (`approvals.table`). A store that can hold them turns
 * such a write into a change request instead (§16.7).
 */
final class ApprovalUnavailable extends SettingsException {}
