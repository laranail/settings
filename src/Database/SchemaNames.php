<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The package's table names, prefixed and validated for use as SQL identifiers.
 */
final readonly class SchemaNames
{
    public function __construct(
        public string $prefix = '',
        private string $categories = 'settings_categories',
        private string $audit = 'settings_audit',
        private string $definitions = 'settings_definitions',
        private string $history = 'settings_history',
        private string $changeRequests = 'settings_change_requests',
        private string $storesTable = 'settings_stores',
    ) {
        if (preg_match('/^[a-z0-9_]{0,32}$/', $prefix) !== 1) {
            throw new InvalidConfiguration(sprintf('laranail.settings.tables.prefix: [%s] must be [a-z0-9_], at most 32 characters.', $prefix));
        }

        self::assertTable($categories, 'laranail.settings.tables.categories');
        self::assertTable($audit, 'laranail.settings.tables.audit');
        self::assertTable($definitions, 'laranail.settings.tables.definitions');
        self::assertTable($history, 'laranail.settings.tables.history');
        self::assertTable($changeRequests, 'laranail.settings.tables.change_requests');
        self::assertTable($storesTable, 'laranail.settings.tables.stores');
    }

    /** @param array<array-key, mixed> $tables `laranail.settings.tables` */
    public static function fromConfig(array $tables): self
    {
        return new self(
            self::string($tables, 'prefix', ''),
            self::string($tables, 'categories', 'settings_categories'),
            self::string($tables, 'audit', 'settings_audit'),
            self::string($tables, 'definitions', 'settings_definitions'),
            self::string($tables, 'history', 'settings_history'),
            self::string($tables, 'change_requests', 'settings_change_requests'),
            self::string($tables, 'stores', 'settings_stores'),
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

    /** The optional definitions table. */
    public function definitions(): string
    {
        return $this->prefix . $this->definitions;
    }

    /** The value history table, one per connection. */
    public function history(): string
    {
        return $this->prefix . $this->history;
    }

    /** The change requests table, one per connection. */
    public function changeRequests(): string
    {
        return $this->prefix . $this->changeRequests;
    }

    /** The persisted configuration table, on the default store's connection. */
    public function stores(): string
    {
        return $this->prefix . $this->storesTable;
    }

    /** @return array{prefix: string, categories: string, audit: string, definitions: string, history: string, change_requests: string, stores: string} the unprefixed names */
    public function toArray(): array
    {
        return ['prefix' => $this->prefix, 'categories' => $this->categories, 'audit' => $this->audit, 'definitions' => $this->definitions, 'history' => $this->history, 'change_requests' => $this->changeRequests, 'stores' => $this->storesTable];
    }

    /** A store's values table: its configured table or its name, prefixed. */
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
