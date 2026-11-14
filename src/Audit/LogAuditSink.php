<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Logging\LogContext;
use Simtabi\Laranail\Settings\Contracts\AuditSink;

/** Writes audit entries to the package's operational log at `info`. */
final readonly class LogAuditSink implements AuditSink
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function record(AuditEntry $entry): void
    {
        $this->logger->info(
            sprintf('laranail/settings audit: %s %s', $entry->action->value, $entry->outcome->value),
            LogContext::of($entry->store, $entry->key, $entry->scope, channel: $entry->channel->value, extra: [
                ...$entry->toArray(),
                'actor' => ['type' => $entry->actorType->value, 'alias' => $entry->actorAlias, 'id' => $entry->actorId],
            ]),
        );
    }
}
