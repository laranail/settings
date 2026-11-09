<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * What `make-store` produced: the migration files it wrote and the configuration entry to add to
 * `config/laranail/settings.php` (printed, never written, because that file is the
 * application's).
 */
final readonly class GeneratedStore
{
    /** @param list<string> $migrations */
    public function __construct(
        public StoreName $store,
        public array $migrations,
        public string $config,
    ) {}
}
