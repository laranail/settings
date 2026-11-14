<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** The migrations `make-store` wrote and the configuration entry to add by hand. */
final readonly class GeneratedStore
{
    /** @param list<string> $migrations */
    public function __construct(
        public StoreName $store,
        public array $migrations,
        public string $config,
    ) {}
}
