<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use ReflectionClass;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Support\EventSummary;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;
use Simtabi\Laranail\Settings\Events\VerificationWasRequired;

/**
 * What an alert says about one event: what happened, where, by whom, how it ended and when.
 * Built from identifiers only, never a value.
 */
final readonly class AlertContext
{
    /** @param array<string, scalar|null> $details the event's other identifiers (counts, versions) */
    public function __construct(
        public string $event,
        public ?string $store,
        public ?string $scope,
        public ?string $key,
        public ?string $locale,
        public string $outcome,
        public string $actor,
        public bool $sensitive,
        public CarbonImmutable $occurredAt,
        public array $details = [],
    ) {}

    /** @param bool $sensitive whether the key's definition is sensitive (or encrypted) */
    public static function fromEvent(object $event, bool $sensitive, CarbonImmutable $occurredAt): self
    {
        $summary = EventSummary::of($event);
        $text = static fn (string $name): ?string => is_string($summary[$name] ?? null) ? $summary[$name] : null;
        $denied = $event instanceof AccessWasDenied || $event instanceof VerificationWasRequired;
        $actor = $text('actor') ?? ($denied ? 'nobody' : 'system');

        return new self(
            event: new ReflectionClass($event)->getShortName(),
            store: $text('store'),
            scope: $text('scope'),
            key: $text('key'),
            locale: $text('locale'),
            outcome: $denied ? 'denied' : 'allowed',
            actor: $actor,
            sensitive: $sensitive,
            occurredAt: $occurredAt,
            details: array_diff_key($summary, array_flip(['store', 'scope', 'key', 'locale', 'actor'])),
        );
    }

    /** One line naming what happened, for a subject or a heading. */
    public function headline(): string
    {
        $what = trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $this->event));

        return $this->key === null
            ? sprintf('laranail/settings: %s%s', $what, $this->store === null ? '' : ' in store ' . $this->store)
            : sprintf('laranail/settings: %s, [%s] in store %s', $what, $this->key, $this->store ?? '?');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'       => $this->event,
            'store'       => $this->store,
            'scope'       => $this->scope,
            'key'         => $this->key,
            'locale'      => $this->locale,
            'outcome'     => $this->outcome,
            'actor'       => $this->actor,
            'sensitive'   => $this->sensitive,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'details'     => $this->details,
        ];
    }
}
