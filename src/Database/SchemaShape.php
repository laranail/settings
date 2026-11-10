<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use BackedEnum;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\StoreMode;
use Simtabi\Laranail\Settings\Enums\TenancyMode;
use Simtabi\Laranail\Settings\Enums\IdentityMode;
use Simtabi\Laranail\Settings\Enums\OwnerKeyType;
use Simtabi\Laranail\Settings\Enums\TranslationsMode;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The schema the migrations produced (§4.0): every schema switch, the table names and one entry
 * per database store. `install` and `make-store` record it in `database/settings-schema.php`,
 * and the runtime reads that record rather than live configuration, so changing configuration
 * after migrating can never make a query name a column that does not exist. Without a recorded
 * file (an application installed before the file existed) the shape is derived from
 * configuration, which is what those migrations used.
 *
 * `VERSION` is this release's shape version. A recorded file with a lower version means a
 * release added columns whose upgrade migration was never published; `doctor` reports it.
 */
final readonly class SchemaShape
{
    public const int VERSION = 1;

    /** @param array<string, StoreSchema> $stores database stores, by name */
    public function __construct(
        public int $version = self::VERSION,
        public IdentityMode $identity = IdentityMode::SlugUid,
        public IdType $ids = IdType::Bigint,
        public OwnerKeyType $ownerKey = OwnerKeyType::String,
        public IdType $ownerKeyType = IdType::Bigint,
        public TranslationsMode $translations = TranslationsMode::Lang,
        public bool $categoriesSoftDeletes = false,
        public bool $definitionsTable = false,
        public SchemaNames $names = new SchemaNames,
        public array $stores = [],
        public TenancyMode $tenancy = TenancyMode::None,
        public bool $historyTable = false,
    ) {}

    /**
     * The shape the current configuration describes.
     *
     * @param array<array-key, mixed> $settings `laranail.settings`
     * @param mixed $dbToolsIdType `laranail.db-tools.id_type`, which `ids.type` follows unless set
     */
    public static function fromConfig(array $settings, mixed $dbToolsIdType = null): self
    {
        $ids = self::section($settings, 'ids');
        $followed = is_string($dbToolsIdType) ? IdType::parse($dbToolsIdType) : null;
        $type = $ids['type'] ?? null;
        $idType = $type === null ? ($followed ?? IdType::Bigint) : (is_string($type) ? IdType::parse($type) : null);

        if (! $idType instanceof IdType) {
            throw new InvalidConfiguration('laranail.settings.ids.type: use bigint, uuid or ulid (or leave it null to follow laranail.db-tools.id_type).');
        }

        $ownerKey = self::enum($ids, 'owner_key', OwnerKeyType::String, OwnerKeyType::tryFrom(...), 'laranail.settings.ids.owner_key: use [string] or [typed].');
        $identity = self::enum(self::section($settings, 'identity'), 'mode', IdentityMode::SlugUid, IdentityMode::tryFrom(...), 'laranail.settings.identity.mode: use slug_uid, slug_per_row or uid_only.');
        $translations = self::enum(self::section($settings, 'translations'), 'mode', TranslationsMode::Lang, TranslationsMode::tryFrom(...), 'laranail.settings.translations.mode: use [lang] or [json].');
        $tenancy = self::enum(self::section($settings, 'tenancy'), 'mode', TenancyMode::None, TenancyMode::tryFrom(...), 'laranail.settings.tenancy.mode: use none, column or database.');
        $categories = self::section($settings, 'categories');
        $definitions = $settings['definitions'] ?? [];

        if (! is_bool($categories['soft_deletes'] ?? false)) {
            throw new InvalidConfiguration('laranail.settings.categories.soft_deletes: true or false.');
        }

        $definitionsTable = is_array($definitions) ? ($definitions['table'] ?? false) : false;

        if (! is_bool($definitionsTable)) {
            throw new InvalidConfiguration('laranail.settings.definitions.table: true or false.');
        }

        $history = self::section($settings, 'history')['enabled'] ?? true;

        if (! is_bool($history)) {
            throw new InvalidConfiguration('laranail.settings.history.enabled: true or false.');
        }

        $default = $settings['default'] ?? 'settings';
        $default = new StoreName(is_string($default) ? $default : 'settings');

        return new self(
            self::VERSION,
            $identity,
            $idType,
            $ownerKey,
            $followed ?? IdType::Bigint,
            $translations,
            $categories['soft_deletes'] ?? false,
            $definitionsTable,
            SchemaNames::fromConfig(self::section($settings, 'tables')),
            self::storesFromConfig($settings, $followed ?? IdType::Bigint, $default),
            $tenancy,
            $history,
        );
    }

    /**
     * Read a recorded schema file.
     *
     * @param array<array-key, mixed> $data what the file returns
     */
    public static function fromArray(array $data): self
    {
        $version = $data['shape_version'] ?? null;

        if (! is_int($version) || $version < 1) {
            throw new InvalidConfiguration('Schema file: `shape_version` must be a positive integer.');
        }

        $switches = self::section($data, 'switches');
        $tables = self::section($data, 'tables');
        $stores = [];

        foreach (self::section($data, 'stores') as $name => $entry) {
            if (! is_array($entry)) {
                throw new InvalidConfiguration(sprintf('Schema file: store [%s] must be an array.', $name));
            }

            $stores[(string) $name] = StoreSchema::fromArray(new StoreName((string) $name), $entry);
        }

        $invalid = static fn (string $switch): InvalidConfiguration => new InvalidConfiguration(sprintf('Schema file: switch [%s] has an unknown value.', $switch));

        return new self(
            $version,
            IdentityMode::tryFrom(self::text($switches, 'identity', 'slug_uid')) ?? throw $invalid('identity'),
            IdType::parse(self::text($switches, 'ids', 'bigint')) ?? throw $invalid('ids'),
            OwnerKeyType::tryFrom(self::text($switches, 'owner_key', 'string')) ?? throw $invalid('owner_key'),
            IdType::parse(self::text($switches, 'owner_key_type', 'bigint')) ?? throw $invalid('owner_key_type'),
            TranslationsMode::tryFrom(self::text($switches, 'translations', 'lang')) ?? throw $invalid('translations'),
            ($switches['categories_soft_deletes'] ?? false) === true,
            ($switches['definitions_table'] ?? false) === true,
            SchemaNames::fromConfig($tables),
            $stores,
            TenancyMode::tryFrom(self::text($switches, 'tenancy', 'none')) ?? throw $invalid('tenancy'),
            // A file recorded before the history table existed describes a schema without it.
            ($switches['history_table'] ?? false) === true,
        );
    }

    /** @return array<string, mixed> what the schema file returns */
    public function toArray(): array
    {
        $stores = [];

        foreach ($this->stores as $name => $store) {
            $stores[$name] = $store->toArray();
        }

        ksort($stores);

        return [
            'shape_version' => $this->version,
            'switches'      => [
                'identity'                => $this->identity->value,
                'ids'                     => $this->ids->value,
                'owner_key'               => $this->ownerKey->value,
                'owner_key_type'          => $this->ownerKeyType->value,
                'translations'            => $this->translations->value,
                'categories_soft_deletes' => $this->categoriesSoftDeletes,
                'definitions_table'       => $this->definitionsTable,
                'tenancy'                 => $this->tenancy->value,
                'history_table'           => $this->historyTable,
            ],
            'tables' => $this->names->toArray(),
            'stores' => $stores,
        ];
    }

    /** The recorded entry of a store, or the default shape for a store not recorded. */
    public function store(StoreName $name): StoreSchema
    {
        return $this->stores[$name->value] ?? new StoreSchema($name);
    }

    public function hasStore(StoreName $name): bool
    {
        return isset($this->stores[$name->value]);
    }

    /** A copy with one more store entry (an existing entry is kept: it describes a table already created). */
    public function withStore(StoreSchema $store): self
    {
        if ($this->hasStore($store->name)) {
            return $this;
        }

        return new self($this->version, $this->identity, $this->ids, $this->ownerKey, $this->ownerKeyType, $this->translations, $this->categoriesSoftDeletes, $this->definitionsTable, $this->names, [...$this->stores, $store->name->value => $store], $this->tenancy, $this->historyTable);
    }

    /**
     * Every difference from another shape, one line each (`identity: slug_uid → uid_only`).
     *
     * @return list<string>
     */
    public function differencesFrom(self $other): array
    {
        $differences = [];
        $mine = self::flatten($this->toArray());
        $theirs = self::flatten($other->toArray());

        foreach (array_unique([...array_keys($theirs), ...array_keys($mine)]) as $path) {
            if ($path === 'shape_version') {
                continue;
            }

            $from = $theirs[$path] ?? null;
            $to = $mine[$path] ?? null;

            if ($from !== $to) {
                $differences[] = sprintf('%s: %s → %s', $path, $this->show($from), $this->show($to));
            }
        }

        return $differences;
    }

    /**
     * @param array<array-key, mixed> $settings
     *
     * @return array<string, StoreSchema> the stores with a database layer
     */
    private static function storesFromConfig(array $settings, IdType $ownerKeyType, StoreName $default): array
    {
        $layers = self::section($settings, 'layers');
        $stores = [];

        foreach (self::section($settings, 'stores') as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $database = null;

            foreach ((array) ($config['layers'] ?? []) as $layer) {
                $layerConfig = is_string($layer) ? ($layers[$layer] ?? null) : null;

                if (is_array($layerConfig) && ($layerConfig['driver'] ?? null) === 'database') {
                    $database = $layerConfig;

                    break;
                }
            }

            if ($database === null) {
                continue;
            }

            try {
                $storeName = new StoreName((string) $name);
            } catch (InvalidIdentifier $exception) {
                throw new InvalidConfiguration(sprintf('laranail.settings.stores.%s: %s', $name, $exception->getMessage()), 0, $exception);
            }

            $connection = $database['connection'] ?? null;
            $stores[$storeName->value] = StoreSchema::fromConfig($storeName, $config, is_string($connection) ? $connection : null, $ownerKeyType, $default);
        }

        foreach ($stores as $store) {
            if ($store->fallback instanceof StoreName && ($stores[$store->fallback->value] ?? null)?->mode === StoreMode::Dedicated) {
                throw new InvalidConfiguration(sprintf('laranail.settings.stores.%s.fallback: [%s] is dedicated too, so it has no global rows either.', $store->name, $store->fallback));
            }
        }

        return $stores;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<array-key, mixed> $section
     * @param T $default
     * @param callable(string): (T|null) $parse
     *
     * @return T
     */
    private static function enum(array $section, string $key, BackedEnum $default, callable $parse, string $message): BackedEnum
    {
        $value = $section[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return (is_string($value) ? $parse($value) : null) ?? throw new InvalidConfiguration($message);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? $value : throw new InvalidConfiguration(sprintf('laranail.settings.%s: an array is required.', $key));
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === null   => 'none',
            is_bool($value)   => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default           => get_debug_type($value),
        };
    }
}
