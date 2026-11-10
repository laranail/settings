<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

/**
 * What an import did, or (dry run) would do: settings created, updated and unchanged by identity
 * (`scope|key|locale`), the ones skipped with the reason, the categories created, and the change
 * requests opened for settings that need approval.
 */
final readonly class ImportReport
{
    /**
     * @param list<string> $created
     * @param list<string> $updated
     * @param list<string> $unchanged
     * @param array<string, string> $skipped identity => reason
     * @param list<string> $categories slugs created
     * @param list<string> $proposed change request ids
     */
    public function __construct(
        public bool $applied,
        public array $created = [],
        public array $updated = [],
        public array $unchanged = [],
        public array $skipped = [],
        public array $categories = [],
        public array $proposed = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applied'    => $this->applied,
            'created'    => $this->created,
            'updated'    => $this->updated,
            'unchanged'  => $this->unchanged,
            'skipped'    => $this->skipped,
            'categories' => $this->categories,
            'proposed'   => $this->proposed,
        ];
    }
}
