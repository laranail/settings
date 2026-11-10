<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\StoreMode;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The recorded shape of one store's values table (§4.0): its mode (D-3), soft deletes (D-9),
 * configured table and connection, and, for a dedicated store, the owner table its `owner_id`
 * points at and the store that holds its global values.
 */
final readonly class StoreSchema
{
    public function __construct(
        public StoreName $name,
        public StoreMode $mode = StoreMode::Polymorphic,
        public bool $softDeletes = false,
        public ?string $table = null,
        public ?string $connection = null,
        public ?string $ownerAlias = null,
        public ?string $ownerTable = null,
        public string $ownerKey = 'id',
        public IdType $ownerKeyType = IdType::Bigint,
        public ?StoreName $fallback = null,
    ) {
        if ($table !== null) {
            SchemaNames::assertTable($table, sprintf('laranail.settings.stores.%s.table', $name));
        }

        if ($mode === StoreMode::Dedicated && ($ownerAlias === null || $ownerTable === null)) {
            throw new InvalidConfiguration(sprintf('laranail.settings.stores.%s: a dedicated store needs exactly one owner alias and owner.table.', $name));
        }

        if ($ownerTable !== null) {
            SchemaNames::assertTable($ownerTable, sprintf('laranail.settings.stores.%s.owner.table', $name));
        }

        if (preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $ownerKey) !== 1) {
            throw new InvalidConfiguration(sprintf('laranail.settings.stores.%s.owner.key: [%s] is not a column name.', $name, $ownerKey));
        }
    }

    /**
     * Read one `laranail.settings.stores.<name>` entry. `$connection` is the store's database
     * layer connection (resolved by the caller from its layers); `$ownerKeyType` is the owner
     * key type when the entry does not set one (laranail.db-tools.id_type).
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromConfig(StoreName $name, array $config, ?string $connection, IdType $ownerKeyType, StoreName $default): self
    {
        $path = 'laranail.settings.stores.' . $name->value;
        $mode = $config['mode'] ?? StoreMode::Polymorphic->value;
        $mode = is_string($mode) ? StoreMode::tryFrom($mode) : null;

        if (! $mode instanceof StoreMode) {
            throw new InvalidConfiguration($path . '.mode: use [polymorphic] or [dedicated].');
        }

        $softDeletes = $config['soft_deletes'] ?? false;

        if (! is_bool($softDeletes)) {
            throw new InvalidConfiguration($path . '.soft_deletes: true or false.');
        }

        $table = $config['table'] ?? null;

        if ($table !== null && ! is_string($table)) {
            throw new InvalidConfiguration($path . '.table: a table name is required.');
        }

        if ($mode === StoreMode::Polymorphic) {
            return new self($name, $mode, $softDeletes, $table, $connection);
        }

        $owners = $config['owners'] ?? [];

        if (! is_array($owners) || count($owners) !== 1 || ! is_string($owners[array_key_first($owners)])) {
            throw new InvalidConfiguration($path . '.owners: a dedicated store holds exactly one owner alias.');
        }

        $owner = $config['owner'] ?? [];

        if (! is_array($owner) || ! is_string($owner['table'] ?? null)) {
            throw new InvalidConfiguration($path . '.owner.table: a dedicated store names the table its owners live in.');
        }

        $key = $owner['key'] ?? 'id';
        $type = $owner['type'] ?? null;
        $parsedType = is_string($type) ? IdType::parse($type) : $ownerKeyType;

        if (! is_string($key) || ! $parsedType instanceof IdType) {
            throw new InvalidConfiguration($path . '.owner: `key` is a column name and `type` one of bigint, uuid, ulid.');
        }

        $fallback = $config['fallback'] ?? $default->value;

        try {
            $fallbackName = new StoreName(is_string($fallback) ? $fallback : '');
        } catch (InvalidIdentifier $exception) {
            throw new InvalidConfiguration($path . '.fallback: ' . $exception->getMessage(), 0, $exception);
        }

        if ($fallbackName->value === $name->value) {
            throw new InvalidConfiguration($path . '.fallback: a dedicated store has no global rows, so its fallback must be another store.');
        }

        return new self($name, $mode, $softDeletes, $table, $connection, $owners[array_key_first($owners)], $owner['table'], $key, $parsedType, $fallbackName);
    }

    /** @param array<array-key, mixed> $entry one entry of the schema file's `stores` */
    public static function fromArray(StoreName $name, array $entry): self
    {
        $mode = StoreMode::tryFrom(self::text($entry, 'mode') ?? 'polymorphic')
            ?? throw new InvalidConfiguration(sprintf('Schema file: store [%s] has an unknown mode.', $name));
        $fallback = self::text($entry, 'fallback');

        return new self(
            $name,
            $mode,
            ($entry['soft_deletes'] ?? false) === true,
            self::text($entry, 'table'),
            self::text($entry, 'connection'),
            self::text($entry, 'owner_alias'),
            self::text($entry, 'owner_table'),
            self::text($entry, 'owner_key') ?? 'id',
            IdType::parse(self::text($entry, 'owner_key_type') ?? 'bigint') ?? IdType::Bigint,
            $fallback === null ? null : new StoreName($fallback),
        );
    }

    /** @return array<string, string|bool|null> the entry written to the schema file */
    public function toArray(): array
    {
        return [
            'mode'           => $this->mode->value,
            'soft_deletes'   => $this->softDeletes,
            'table'          => $this->table,
            'connection'     => $this->connection,
            'owner_alias'    => $this->ownerAlias,
            'owner_table'    => $this->ownerTable,
            'owner_key'      => $this->mode === StoreMode::Dedicated ? $this->ownerKey : null,
            'owner_key_type' => $this->mode === StoreMode::Dedicated ? $this->ownerKeyType->value : null,
            'fallback'       => $this->fallback?->value,
        ];
    }

    public function isDedicated(): bool
    {
        return $this->mode === StoreMode::Dedicated;
    }

    /** @param array<array-key, mixed> $entry */
    private static function text(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
