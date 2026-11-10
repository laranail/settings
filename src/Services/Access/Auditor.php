<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Access;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * Builds audit entries and hands each to every configured sink, then to any sink added at
 * runtime with `auditTo()`. A call without an actor is recorded as `system`.
 */
final readonly class Auditor
{
    /**
     * @param list<AuditSink> $sinks
     * @param Closure(): CarbonImmutable $clock
     */
    public function __construct(
        private array $sinks,
        private Closure $clock,
        private ?RuntimeConfiguration $runtime = null,
    ) {}

    /** A copy that also writes to these sinks (the fake uses it to record entries in memory). */
    public function alsoTo(AuditSink ...$sinks): self
    {
        return new self([...$this->sinks, ...array_values($sinks)], $this->clock, $this->runtime);
    }

    /** @param array<string, scalar|null> $meta identifiers only, never a value */
    public function record(
        AuditAction $action,
        AuditOutcome $outcome,
        ?Subject $subject,
        ?Actor $actor,
        ?string $reason = null,
        ?int $versionFrom = null,
        ?int $versionTo = null,
        array $meta = [],
    ): AuditEntry {
        $actor ??= Actor::system();

        $entry = new AuditEntry(
            id: (string) Str::ulid(),
            occurredAt: ($this->clock)(),
            action: $action,
            outcome: $outcome,
            store: $subject?->store->value,
            scope: $subject?->scope()->value,
            key: $subject?->key?->value,
            locale: $subject?->locale->value,
            versionFrom: $versionFrom,
            versionTo: $versionTo,
            actorType: $actor->type,
            actorAlias: $actor->alias,
            actorId: $actor->id,
            channel: $actor->channel,
            ip: $actor->context->ip,
            userAgent: $actor->context->userAgent,
            requestId: $actor->context->requestId,
            reason: $reason ?? $actor->context->reason,
            meta: $meta,
        );

        foreach ([...$this->sinks, ...$this->runtime?->auditSinks() ?? []] as $sink) {
            $sink->record($entry);
        }

        return $entry;
    }
}
