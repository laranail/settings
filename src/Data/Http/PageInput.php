<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Http;

/** A page of a cursor-paginated list: how many, after which cursor, and any filters. */
final readonly class PageInput
{
    /** @param array<string, string> $filters */
    public function __construct(
        public int $limit = 50,
        public ?string $cursor = null,
        public array $filters = [],
    ) {}

    public function filter(string $name): ?string
    {
        return $this->filters[$name] ?? null;
    }
}
