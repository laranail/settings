<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Resolution;

use Simtabi\Laranail\Settings\Data\Lookup;

/**
 * How a read was resolved: every (store, scope, locale, layer) tried, in order, and the result.
 * For an actor who may not view the value, the result is redacted rather than refused, so the
 * path stays explainable.
 */
final readonly class Explanation
{
    /** @param list<string> $steps */
    public function __construct(
        public Lookup $lookup,
        public array $steps,
    ) {}

    /** The same explanation with the answer's value withheld. */
    public function redacted(): self
    {
        return new self($this->lookup->redacted(), $this->steps);
    }

    public function isRedacted(): bool
    {
        return $this->lookup->isRedacted();
    }
}
