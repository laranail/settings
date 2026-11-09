<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The package's table names: `tables.prefix` (D-4) prepended to `tables.categories`,
 * `tables.audit` and each store's values table (`stores.<name>.table`, default the store name).
 * Every name is validated, because it is interpolated into SQL identifiers.
 */
final readonly class SchemaNames
{
    public function __construct(
        public string $prefix = '',
        private string $categories = 'settings_categories',
        private string $audit = 'settings_audit',
    ) {
        if (preg_match('/^[a-z0-9_]{0,32}$/', $prefix) !== 1) {
            throw new InvalidConfiguration(sprintf('laranail.settings.tables.prefix: [%s] must be [a-z0-9_], at most 32 characters.', $prefix));
        }

        self::assertTable($categories, 'laranail.settings.tables.categories');
        self::assertTable($audit, 'laranail.settings.tables.audit');
    }

    /** @param array<array-key, mixed> $tables `laranail.settings.tables` */
    public static function fromConfig(array $tables): self
    {
        return new self(
            self::string($tables, 'prefix', ''),
            self::string($tables, 'categories', 'settings_categories'),
            self::string($tables, 'audit', 'settings_audit'),
        );
    }

    public static function assertTable(string $table, string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table) !== 1) {
            throw new InvalidConfiguration(sprintf('%s: [%s] must be [a-z][a-z0-9_]*, at most 64 characters.', $path, $table));
        }
    }

    public function categories(): string
    {
        return $this->prefix . $this->categories;
    }

    public function audit(): string
    {
        return $this->prefix . $this->audit;
    }

    /** A store's values table: its configured table, or its name, after the prefix. */
    public function store(StoreName $store, ?string $configured = null): string
    {
        return $this->prefix . ($configured ?? $store->value);
    }

    /** @param array<array-key, mixed> $tables */
    private static function string(array $tables, string $key, string $default): string
    {
        $value = $tables[$key] ?? $default;

        return is_string($value) ? $value : throw new InvalidConfiguration(sprintf('laranail.settings.tables.%s: a string is required.', $key));
    }
}
