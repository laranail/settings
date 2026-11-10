<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * One step of a settings migration (G-17): copy every value of `fromKey` in `fromStore` (every
 * owner and locale) to `toKey` in `toStore` and remove the original (a rename or a move); or, with
 * no destination, remove the key everywhere (`delete`); or, with a value and no source rows,
 * store it as the global value unless one exists (`add`).
 */
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
