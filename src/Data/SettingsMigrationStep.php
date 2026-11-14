<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** One settings migration step: a rename or move, a delete, or adding a global value. */
final readonly class SettingsMigrationStep
{
    public const string RENAME = 'rename';

    public const string DELETE = 'delete';

    public const string ADD = 'add';

    public function __construct(
        public string $operation,
        public StoreName $fromStore,
        public Key $fromKey,
        public ?StoreName $toStore = null,
        public ?Key $toKey = null,
        public mixed $value = null,
    ) {}
}
