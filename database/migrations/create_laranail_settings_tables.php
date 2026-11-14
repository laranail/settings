<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Database\Schema\ColumnDefinition;
use Simtabi\Laranail\Settings\Database\SchemaNames;

/*
 * Every table laranail/settings uses, in dependency order:
 *
 *   categories        shared by the stores on a connection, nested by parent_id
 *   definitions       on the default store's connection
 *   <store>           one values table per database store
 *   audit             actions and denials, never values
 *   history           every value by version
 *   change requests   writes waiting for approval
 *   stores            stores and layers saved through the API, on the default store's connection
 *
 * The shape is fixed. The one switch is `tenancy.mode`: in `column` mode every tenant-owned table
 * gets a `tenant` column, and the tenant leads its unique and lookup indexes.
 *
 * `laranail::settings.make-store` copies this file for one store by rewriting the $only line
 * (and, with --connection, the $pinnedConnection line). Keep both lines exactly as they are.
 */
return new class extends Migration
{
    /** null: every configured store plus the shared tables; a store name: that store only. */
    private ?string $only = null;

    private ?string $pinnedConnection = null;

    public function getConnection(): ?string
    {
        return $this->pinnedConnection ?? $this->store($this->primaryStore())['connection'];
    }

    public function up(): void
    {
        $names = $this->names();

        foreach ($this->plan() as $connection => $tables) {
            $schema = Schema::connection($connection);
            $tenant = $this->tenantColumn();
            $binary = $this->binaryCollation($connection);

            if ($tables['shared'] && $this->missing($connection, $names->categories())) {
                $schema->create($names->categories(), function (Blueprint $table) use ($names, $tenant): void {
                    $name = $names->categories();

                    $table->ulid('id');
                    // Declared before the self-referencing foreign key: Postgres needs the key to exist first.
                    $table->primary('id');

                    if ($tenant) {
                        $table->string('tenant', 64)->default('');
                    }

                    $table->string('store', 64);
                    $table->ulid('parent_id')->nullable();
                    $table->json('title');
                    $table->string('slug', 128);
                    $table->json('description')->nullable();
                    $table->string('icon')->nullable();
                    $table->unsignedInteger('sort_order')->default(0);
                    $table->boolean('is_system')->default(false);
                    $table->json('meta')->nullable();
                    $table->timestamps();

                    $table->unique($this->withTenant(['store', 'slug']), $name . '_store_slug_unique');
                    $table->unique(['id', 'store'], $name . '_id_store_unique');
                    $table->index('parent_id', $name . '_parent_index');
                    $table->foreign('parent_id', $name . '_parent_foreign')->references('id')->on($name)->nullOnDelete();
                });
            }

            if ($tables['definitions'] && $this->missing($connection, $names->definitions())) {
                $schema->create($names->definitions(), function (Blueprint $table) use ($names): void {
                    $name = $names->definitions();

                    $table->ulid('id')->primary();
                    $table->string('store', 64);
                    $table->string('slug', 191);
                    $table->string('key', 191);
                    $table->string('group', 64);
                    $table->ulid('category_id')->nullable();
                    $table->string('type', 64)->nullable();
                    $table->longText('default')->nullable();
                    $table->json('label')->nullable();
                    $table->json('description')->nullable();
                    $table->json('rules')->nullable();
                    $table->boolean('is_encrypted')->default(false);
                    $table->boolean('is_public')->default(false);
                    $table->boolean('is_locked')->default(false);
                    $table->boolean('autoload')->default(true);
                    $table->boolean('inherits')->nullable();
                    $table->json('overridable_by')->nullable();
                    $table->boolean('is_system')->default(false);
                    $table->json('meta')->nullable();
                    $table->timestamps();

                    $table->unique(['store', 'key'], $name . '_store_key_unique');
                    $table->unique(['store', 'slug'], $name . '_store_slug_unique');
                    $table->index('category_id', $name . '_category_index');
                    $table->foreign('category_id', $name . '_category_foreign')->references('id')->on($names->categories())->nullOnDelete();
                });
            }

            foreach ($tables['stores'] as $store) {
                if ($this->missing($connection, $store['table'])) {
                    $schema->create($store['table'], function (Blueprint $table) use ($binary, $names, $store, $tenant): void {
                        $this->valuesTable($table, $store, $names, $tenant, $binary);
                    });
                }
            }

            if ($tables['shared'] && $this->missing($connection, $names->audit())) {
                $schema->create($names->audit(), function (Blueprint $table) use ($names, $tenant): void {
                    $name = $names->audit();

                    $table->ulid('id')->primary();

                    if ($tenant) {
                        $table->string('tenant', 64)->default('');
                    }

                    $table->timestamp('occurred_at');
                    $table->string('action', 32);
                    $table->string('outcome', 16);
                    $table->string('store', 64)->nullable();
                    $table->string('scope', 128)->nullable();
                    $table->string('key', 191)->nullable();
                    $table->string('locale', 16)->nullable();
                    $table->unsignedInteger('version_from')->nullable();
                    $table->unsignedInteger('version_to')->nullable();
                    $table->char('change_request', 26)->nullable();
                    $table->string('actor_type', 16);
                    $table->string('actor_alias', 64)->nullable();
                    $table->string('actor_id', 64)->nullable();
                    $table->string('channel', 16);
                    $table->string('ip', 45)->nullable();
                    $table->string('user_agent')->nullable();
                    $table->string('request_id')->nullable();
                    $table->text('reason')->nullable();
                    $table->json('meta')->nullable();

                    $table->index('occurred_at', $name . '_occurred_index');
                    $table->index($this->withTenant(['store', 'scope', 'key']), $name . '_subject_index');
                    $table->index(['actor_alias', 'actor_id'], $name . '_actor_index');
                });
            }

            // One row per version of a setting, with the payload before and after exactly as the
            // values table stores it. `setting_ref` is the values row's `uid`.
            if ($tables['shared'] && $this->missing($connection, $names->history())) {
                $schema->create($names->history(), function (Blueprint $table) use ($binary, $names, $tenant): void {
                    $name = $names->history();

                    $table->ulid('id')->primary();

                    if ($tenant) {
                        $this->collate($table->string('tenant', 64)->default(''), $binary);
                    }

                    $table->string('store', 64);
                    $table->string('setting_ref', 36)->nullable();
                    $this->collate($table->string('scope', 128), $binary);
                    $this->collate($table->string('key', 191), $binary);
                    $this->collate($table->string('locale', 16)->default(''), $binary);
                    $table->string('operation', 16);
                    $table->longText('old_value')->nullable();
                    $table->longText('new_value')->nullable();
                    $table->string('old_type', 64)->nullable();
                    $table->string('new_type', 64)->nullable();
                    $table->boolean('old_encrypted')->default(false);
                    $table->boolean('new_encrypted')->default(false);
                    $table->unsignedInteger('version');
                    $table->string('causer_type', 16);
                    $table->string('causer_alias', 64)->nullable();
                    $table->string('causer_id', 64)->nullable();
                    $table->text('reason')->nullable();
                    $table->timestamp('created_at');

                    $table->index($this->withTenant(['store', 'scope', 'key', 'locale', 'version']), $name . '_subject_index');
                    $table->index('created_at', $name . '_created_index');
                });
            }

            // Writes waiting for approval. `payload` is stored as the values table would store it,
            // and `base_version` lets a request whose setting has since changed become `stale`.
            if ($tables['shared'] && $this->missing($connection, $names->changeRequests())) {
                $schema->create($names->changeRequests(), function (Blueprint $table) use ($binary, $names, $tenant): void {
                    $name = $names->changeRequests();

                    $table->ulid('id')->primary();

                    if ($tenant) {
                        $this->collate($table->string('tenant', 64)->default(''), $binary);
                    }

                    $table->string('store', 64);
                    $this->collate($table->string('scope', 128), $binary);
                    $this->collate($table->string('key', 191), $binary);
                    $this->collate($table->string('locale', 16)->default(''), $binary);
                    $table->string('operation', 16);
                    $table->longText('payload')->nullable();
                    $table->string('type', 64)->nullable();
                    $table->boolean('is_encrypted')->default(false);
                    $table->unsignedInteger('base_version')->default(0);
                    $table->string('status', 16);
                    $table->string('requested_by_type', 16);
                    $table->string('requested_by_alias', 64)->nullable();
                    $table->string('requested_by_id', 64)->nullable();
                    $table->json('approvals')->nullable();
                    $table->string('decided_by_type', 16)->nullable();
                    $table->string('decided_by_alias', 64)->nullable();
                    $table->string('decided_by_id', 64)->nullable();
                    $table->timestamp('decided_at')->nullable();
                    $table->text('reason')->nullable();
                    $table->text('decision_reason')->nullable();
                    $table->timestamp('expires_at')->nullable();
                    $table->timestamps();

                    $table->index($this->withTenant(['status', 'store']), $name . '_status_index');
                    $table->index($this->withTenant(['store', 'scope', 'key', 'locale']), $name . '_subject_index');
                    $table->index('expires_at', $name . '_expires_index');
                });
            }

            // Store and layer entries saved through the API; `config` holds the entry as JSON.
            if ($tables['definitions'] && $this->missing($connection, $names->stores())) {
                $schema->create($names->stores(), function (Blueprint $table) use ($names): void {
                    $table->ulid('id')->primary();
                    $table->string('kind', 16);
                    $table->string('name', 64);
                    $table->json('config');
                    $table->timestamps();

                    $table->unique(['kind', 'name'], $names->stores() . '_kind_name_unique');
                });
            }
        }
    }

    public function down(): void
    {
        $names = $this->names();

        foreach (array_reverse($this->plan(), true) as $connection => $tables) {
            $schema = Schema::connection($connection);

            if ($tables['definitions']) {
                $schema->dropIfExists($names->stores());
            }

            if ($tables['shared']) {
                $schema->dropIfExists($names->changeRequests());
                $schema->dropIfExists($names->history());
                $schema->dropIfExists($names->audit());
            }

            foreach (array_reverse($tables['stores']) as $store) {
                $schema->dropIfExists($store['table']);
            }

            if ($tables['definitions']) {
                $schema->dropIfExists($names->definitions());
            }

            if ($tables['shared']) {
                $schema->dropIfExists($names->categories());
            }
        }
    }

    /**
     * One store's values. The identity columns are binary-collated, so `Theme` and `theme` stay
     * two rows on MySQL as they do elsewhere. A dedicated store keeps an `owner_id` foreign key to
     * its owner table instead of the polymorphic `settable_type` and `settable_id`.
     *
     * @param array{table: string, connection: ?string, owner: ?array{table: string, key: string, type: string}} $store
     */
    private function valuesTable(Blueprint $table, array $store, SchemaNames $names, bool $tenant, ?string $binary): void
    {
        $index = substr($store['table'], 0, 40);
        $owner = $store['owner'];

        $table->ulid('id')->primary();

        if ($tenant) {
            $this->collate($table->string('tenant', 64)->default(''), $binary);
        }

        if ($owner === null) {
            $table->string('settable_type', 64)->nullable();
            $table->string('settable_id', 36)->nullable();
        } else {
            match ($owner['type']) {
                'uuid'  => $table->char('owner_id', 36),
                'ulid'  => $table->char('owner_id', 26),
                default => $table->unsignedBigInteger('owner_id'),
            };
        }

        $this->collate($table->string('scope', 128), $binary);
        $table->ulid('category_id')->nullable();
        $table->string('category_store', 64)->nullable();
        $table->string('group', 64);
        $this->collate($table->string('key', 191), $binary);
        $table->ulid('uid');
        $this->collate($table->string('locale', 16)->default(''), $binary);
        $table->longText('value')->nullable();
        $table->string('type', 64)->nullable();
        $table->json('label')->nullable();
        $table->json('description')->nullable();
        $table->boolean('is_encrypted')->default(false);
        $table->boolean('is_locked')->default(false);
        $table->boolean('is_public')->default(false);
        $table->boolean('autoload')->default(true);
        $table->unsignedInteger('sort_order')->default(0);
        $table->json('meta')->nullable();
        $table->unsignedInteger('version')->default(1);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();

        $table->unique($this->withTenant(['scope', 'key', 'locale']), $index . '_identity_unique');
        $table->unique('uid', $index . '_uid_unique');

        if ($owner === null) {
            $table->index(['settable_type', 'settable_id'], $index . '_settable_index');
        } else {
            $table->index('owner_id', $index . '_owner_index');
            $table->foreign('owner_id', $index . '_owner_foreign')->references($owner['key'])->on($owner['table'])->cascadeOnDelete();
        }

        $table->index('category_id', $index . '_category_index');
        $table->index(['autoload', 'scope'], $index . '_autoload_index');
        $table->foreign(['category_id', 'category_store'], $index . '_category_foreign')
            ->references(['id', 'store'])->on($names->categories())->nullOnDelete();
    }

    /**
     * What to create on each connection, the default store's connection first.
     *
     * @return array<string, array{shared: bool, definitions: bool, stores: list<array{table: string, connection: ?string, owner: ?array{table: string, key: string, type: string}}>}>
     */
    private function plan(): array
    {
        if ($this->only !== null) {
            $store = $this->store(new StoreName($this->only));
            $connection = $this->pinnedConnection ?? $this->connectionName($store['connection']);

            return [$connection => ['shared' => $this->pinnedConnection !== null, 'definitions' => false, 'stores' => [$store]]];
        }

        $main = $this->connectionName($this->store($this->primaryStore())['connection']);
        $plan = [$main => ['shared' => true, 'definitions' => true, 'stores' => []]];

        foreach ($this->databaseStores() as $name) {
            $store = $this->store(new StoreName($name));
            $connection = $this->connectionName($store['connection']);
            $plan[$connection] ??= ['shared' => true, 'definitions' => false, 'stores' => []];
            $plan[$connection]['stores'][] = $store;
        }

        return $plan;
    }

    /** @return list<string> the configured stores with a database layer */
    private function databaseStores(): array
    {
        $stores = [];

        foreach ((array) config('laranail.settings.stores', []) as $name => $store) {
            if (is_array($store) && $this->databaseLayer($store) !== null) {
                $stores[] = (string) $name;
            }
        }

        return $stores;
    }

    /**
     * A store's table, connection and, for a dedicated store, its owner table.
     *
     * @return array{table: string, connection: ?string, owner: ?array{table: string, key: string, type: string}}
     */
    private function store(StoreName $name): array
    {
        $store = config('laranail.settings.stores.' . $name->value);
        $store = is_array($store) ? $store : [];
        $table = $store['table'] ?? null;
        $layer = $this->databaseLayer($store);
        $connection = $this->pinnedConnection ?? ($layer['connection'] ?? null);
        $owner = null;

        if (($store['mode'] ?? 'polymorphic') === 'dedicated' && is_array($store['owner'] ?? null) && is_string($store['owner']['table'] ?? null)) {
            $type = $store['owner']['type'] ?? config('laranail.db-tools.id_type') ?? 'bigint';

            $owner = [
                'table' => $store['owner']['table'],
                'key'   => is_string($store['owner']['key'] ?? null) ? $store['owner']['key'] : 'id',
                'type'  => is_string($type) ? strtolower($type) : 'bigint',
            ];
        }

        return [
            'table'      => $this->names()->store($name, is_string($table) ? $table : null),
            'connection' => is_string($connection) ? $connection : null,
            'owner'      => $owner,
        ];
    }

    /**
     * The store's first database layer, or null.
     *
     * @param array<array-key, mixed> $store
     *
     * @return array<array-key, mixed>|null
     */
    private function databaseLayer(array $store): ?array
    {
        foreach ((array) ($store['layers'] ?? []) as $layer) {
            $config = is_string($layer) ? config('laranail.settings.layers.' . $layer) : null;

            if (is_array($config) && ($config['driver'] ?? null) === 'database') {
                return $config;
            }
        }

        return null;
    }

    /**
     * Whether to create this table. A single store's copy skips a table that already exists,
     * because `migrate:fresh` replays both it and the full migration.
     */
    private function missing(string $connection, string $table): bool
    {
        return $this->only === null || ! Schema::connection($connection)->hasTable($table);
    }

    private function collate(ColumnDefinition $column, ?string $binary): ColumnDefinition
    {
        return $binary === null ? $column : $column->collation($binary);
    }

    /** The binary collation for identity columns on this connection's driver. */
    private function binaryCollation(string $connection): ?string
    {
        return match (DB::connection($connection)->getDriverName()) {
            'mysql', 'mariadb' => 'utf8mb4_bin',
            'pgsql'            => 'C',
            default            => null,
        };
    }

    /**
     * Index columns, led by `tenant` in column-mode tenancy.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function withTenant(array $columns): array
    {
        return $this->tenantColumn() ? ['tenant', ...$columns] : $columns;
    }

    private function tenantColumn(): bool
    {
        return config('laranail.settings.tenancy.mode', 'none') === 'column';
    }

    private function names(): SchemaNames
    {
        $tables = config('laranail.settings.tables', []);

        return SchemaNames::fromConfig(is_array($tables) ? $tables : []);
    }

    private function primaryStore(): StoreName
    {
        if ($this->only !== null) {
            return new StoreName($this->only);
        }

        $default = config('laranail.settings.default', 'settings');

        return new StoreName(is_string($default) ? $default : 'settings');
    }

    private function connectionName(?string $connection): string
    {
        return $connection ?? DB::getDefaultConnection();
    }
};
