<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;

/**
 * Writes each audit entry to the package's log channel at `info`, so `logging.channel` and
 * `logging.level` apply to it too.
 */
final readonly class LogAuditSink implements AuditSink
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function record(AuditEntry $entry): void
    {
        $this->logger->info(
            sprintf('laranail/settings audit: %s %s', $entry->action->value, $entry->outcome->value),
            $entry->toArray(),
        );
    }
}
