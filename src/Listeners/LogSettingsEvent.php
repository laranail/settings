<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Listeners;

use ReflectionClass;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Events\LayerSkipped;
use Simtabi\Laranail\Settings\Support\EventSummary;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;
use Simtabi\Laranail\Settings\Events\VerificationWasRequired;

/**
 * Writes every package event to the operational log (`logging.channel`), identifiers only:
 * refusals at `warning`, everything else at `info`. Off by default
 * (`listeners.log`), because the audit trail already records the actions these events announce.
 */
final readonly class LogSettingsEvent
{
    /** What is logged: every event an alert can name, plus a skipped bootstrap layer. */
    public const array EVENTS = [...SendSettingsAlert::EVENTS, LayerSkipped::class];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function handle(object $event): void
    {
        $refusal = $event instanceof AccessWasDenied || $event instanceof VerificationWasRequired;
        $message = sprintf('laranail/settings event: %s', new ReflectionClass($event)->getShortName());

        $refusal ? $this->logger->warning($message, EventSummary::of($event)) : $this->logger->info($message, EventSummary::of($event));
    }
}
