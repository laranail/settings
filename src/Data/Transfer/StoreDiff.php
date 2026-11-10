<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

/**
 * A store compared with an export (G-42): identities (`scope|key|locale`) only in the document
 * (`added`, as an import would create them), only in the store (`removed`), in both with
 * different values (`changed`) or equal ones (`unchanged`), and those that cannot be compared
 * because a side is redacted or encrypted (`unknown`). Never carries a value.
 */
final readonly class StoreDiff
{
    /**
     * @param list<string> $added
     * @param list<string> $removed
     * @param list<string> $changed
     * @param list<string> $unchanged
     * @param list<string> $unknown
     */
    public function __construct(
        public array $added = [],
        public array $removed = [],
        public array $changed = [],
        public array $unchanged = [],
        public array $unknown = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return ['added' => $this->added, 'removed' => $this->removed, 'changed' => $this->changed, 'unchanged' => $this->unchanged, 'unknown' => $this->unknown];
    }
}
