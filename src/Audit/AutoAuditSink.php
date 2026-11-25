<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;

/**
 * The default sink (`audit.sinks = ['auto']`): the database sink for entries whose store has an
 * active database layer, the log sink otherwise (a file-only store, or a store whose database
 * layer bootstrap mode is skipping).
 */
final readonly class AutoAuditSink implements AuditSink
{
    public function __construct(
        private DatabaseAuditSink $database,
        private AuditSink $fallback,
    ) {}

    public function record(AuditEntry $entry): void
    {
        if ($this->database->accepts($entry)) {
            $this->database->record($entry);

            return;
        }

        $this->fallback->record($entry);
    }
}
