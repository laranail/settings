<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * What a promotion did per identity: promoted, or skipped as `exists` or `undefined`. `kept` lists
 * the values a selective promotion left in the runtime file.
 */
final readonly class PromotionReport
{
    /**
     * @param list<string> $promoted
     * @param array<string, string> $skipped identity => reason
     * @param list<string> $kept
     */
    public function __construct(
        public string $store,
        public array $promoted = [],
        public array $skipped = [],
        public ?string $archive = null,
        public array $kept = [],
    ) {}

    /** @return array{store: string, promoted: list<string>, skipped: array<string, string>, archive: ?string, kept: list<string>} */
    public function toArray(): array
    {
        return [
            'store'    => $this->store,
            'promoted' => $this->promoted,
            'skipped'  => $this->skipped,
            'archive'  => $this->archive,
            'kept'     => $this->kept,
        ];
    }
}
