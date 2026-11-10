<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** What a promotion did per identity: promoted, or skipped as `exists` or `undefined`. */
final readonly class PromotionReport
{
    /**
     * @param list<string> $promoted
     * @param array<string, string> $skipped identity => reason
     */
    public function __construct(
        public string $store,
        public array $promoted = [],
        public array $skipped = [],
        public ?string $archive = null,
    ) {}

    /** @return array{store: string, promoted: list<string>, skipped: array<string, string>, archive: ?string} */
    public function toArray(): array
    {
        return [
            'store'    => $this->store,
            'promoted' => $this->promoted,
            'skipped'  => $this->skipped,
            'archive'  => $this->archive,
        ];
    }
}
