<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Enums\IdType;
use Illuminate\Database\Migrations\Migration;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Database\Schema\ColumnDefinition;
use Simtabi\Laranail\Settings\Enums\TenancyMode;
use Simtabi\Laranail\Settings\Enums\IdentityMode;
use Simtabi\Laranail\Settings\Enums\OwnerKeyType;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Enums\TranslationsMode;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;

/*
 * Every table laranail/settings uses, in dependency order:
 *
 *   categories        shared by the stores on a connection, nested by parent_id
 *   definitions       optional (definitions.table), on the default store's connection
 *   <store>           one values table per database store
 *   audit             actions and denials, never values
 *   history           every value by version (history.enabled)
 *   change requests   pending approvals (approvals.table)
 *   stores            persisted store and layer configuration (configuration.persisted)
 *
 * The shape (key types, identity mode, owner keys, soft deletes, translations, tenancy, table
 * names and connections) comes from the recorded schema file, database/settings-schema.php, or
 * from configuration when nothing is recorded.
 *
 * `laranail::settings.make-store` copies this file for one store by rewriting the $only line
 * (and, with --connection, the $pinnedConnection line). Keep both lines exactly as they are.
 */
return new class extends Migration
{
    /** null: every recorded store plus the shared tables; a store name: that store only. */
    private ?string $only = null;

    private ?string $pinnedConnection = null;

    public function getConnection(): ?string
    {
        return $this->pinnedConnection ?? $this->shape()->store($this->primaryStore())->connection;
    }

    public function up(): void
    {
        $shape = $this->shape();
        $names = $shape->names;

        foreach ($this->plan() as $connection => $tables) {
            if ($tables['shared'] && $this->missing($connection, $names->categories())) {
                $this->createCategories($connection, $shape);
            }

            if ($tables['definitions']) {
                $this->createDefinitions($connection, $shape);
            }

            foreach ($tables['stores'] as $store) {
                if ($this->missing($connection, $names->store($store->name, $store->table))) {
                    $this->createValues($connection, $shape, $store);
                }
            }

            if ($tables['shared'] && $this->missing($connection, $names->audit())) {
                $this->createAudit($connection, $shape);
            }

            if ($tables['shared'] && $shape->historyTable && $this->missing($connection, $names->history())) {
                $this->createHistory($connection, $shape);
            }

            if ($tables['shared'] && $shape->changeRequestsTable && $this->missing($connection, $names->changeRequests())) {
                $this->createChangeRequests($connection, $shape);
            }

            if ($tables['definitions'] && $shape->storesTable) {
                $this->createStores($connection, $shape);
            }
        }
    }

    public function down(): void
    {
        $shape = $this->shape();
        $names = $shape->names;

        foreach (array_reverse($this->plan(), true) as $connection => $tables) {
            $schema = Schema::connection($connection);

            if ($tables['definitions'] && $shape->storesTable) {
                $schema->dropIfExists($names->stores());
            }

            if ($tables['shared'] && $shape->changeRequestsTable) {
                $schema->dropIfExists($names->changeRequests());
            }

            if ($tables['shared'] && $shape->historyTable) {
                $schema->dropIfExists($names->history());
            }

            if ($tables['shared']) {
                $schema->dropIfExists($names->audit());
            }

            foreach (array_reverse($tables['stores']) as $store) {
                $schema->dropIfExists($names->store($store->name, $store->table));
            }

            if ($tables['definitions']) {
                $schema->dropIfExists($names->definitions());
            }

            if ($tables['shared']) {
                $schema->dropIfExists($names->categories());
            }
        }
    }

    private function createCategories(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->categories();

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($name, $shape): void {
            $this->key($table, 'id', $shape->ids, primary: true);
            $this->tenant($table, $shape);
            $table->string('store', 64);
            $this->key($table, 'parent_id', $shape->ids)->nullable();
            $this->translatable($table, 'title', $shape, nullable: false);
            $table->string('slug', 128);
            $this->translatable($table, 'description', $shape);
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            if ($shape->categoriesSoftDeletes) {
                $table->timestamp('deleted_at')->nullable();
            }

            $table->unique([...$this->tenantColumns($shape), 'store', 'slug'], $name . '_store_slug_unique');
            $table->unique(['id', 'store'], $name . '_id_store_unique');
            $table->index('parent_id', $name . '_parent_index');
            $table->foreign('parent_id', $name . '_parent_foreign')->references('id')->on($name)->nullOnDelete();
        });
    }

    private function createDefinitions(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->definitions();
        $categories = $shape->names->categories();

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($categories, $name, $shape): void {
            $this->key($table, 'id', $shape->ids, primary: true);
            $table->string('store', 64);
            $table->string('slug', 191);
            $table->string('key', 191);
            $table->string('group', 64);
            $this->key($table, 'category_id', $shape->ids)->nullable();
            $table->string('type', 64)->nullable();
            $table->longText('default')->nullable();
            $this->translatable($table, 'label', $shape);
            $this->translatable($table, 'description', $shape);
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
            $table->foreign('category_id', $name . '_category_foreign')->references('id')->on($categories)->nullOnDelete();
        });
    }

    /**
     * One store's values. The identity columns are binary-collated, so `Theme` and `theme` stay
     * two rows on MySQL as they do elsewhere.
     */
    private function createValues(string $connection, SchemaShape $shape, StoreSchema $store): void
    {
        $name = $shape->names->store($store->name, $store->table);
        $index = substr($name, 0, 40);
        $binary = $this->binaryCollation($connection);

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($binary, $index, $shape, $store): void {
            $this->key($table, 'id', $shape->ids, primary: true);

            if ($shape->tenancy === TenancyMode::Column) {
                $this->collate($table->string('tenant', 64)->default(''), $binary);
            }

            if ($store->isDedicated()) {
                $this->key($table, 'owner_id', $store->ownerKeyType);
            } else {
                $table->string('settable_type', 64)->nullable();
                $shape->ownerKey === OwnerKeyType::Typed
                    ? $this->key($table, 'settable_id', $shape->ownerKeyType)->nullable()
                    : $table->string('settable_id', 36)->nullable();
            }

            $this->collate($table->string('scope', 128), $binary);
            $this->key($table, 'category_id', $shape->ids)->nullable();
            $table->string('category_store', 64)->nullable();
            $table->string('group', 64);
            $this->collate($table->string('key', 191), $binary);

            if ($shape->identity->hasSlug()) {
                $this->collate($table->string('slug', $shape->identity === IdentityMode::SlugPerRow ? 40 : 191)->nullable(), $binary);
            }

            if ($shape->identity->hasUid()) {
                $table->char('uid', 26)->nullable();
            }

            $this->collate($table->string('locale', 16)->default(''), $binary);
            $table->longText('value')->nullable();
            $table->string('type', 64)->nullable();
            $this->translatable($table, 'label', $shape);
            $this->translatable($table, 'description', $shape);
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_public')->default(false);
            $table->boolean('autoload')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            if ($store->softDeletes) {
                $table->timestamp('deleted_at')->nullable();
            }

            $this->indexValues($table, $index, $shape, $store);
        });
    }

    private function indexValues(Blueprint $table, string $index, SchemaShape $shape, StoreSchema $store): void
    {
        $tenant = $this->tenantColumns($shape);

        $table->unique([...$tenant, 'scope', 'key', 'locale'], $index . '_identity_unique');

        if ($shape->identity->hasUid()) {
            $table->unique('uid', $index . '_uid_unique');
        }

        match ($shape->identity) {
            IdentityMode::SlugPerRow => $table->unique([...$tenant, 'slug'], $index . '_slug_unique'),
            IdentityMode::SlugUid    => $table->index('slug', $index . '_slug_index'),
            IdentityMode::UidOnly    => null,
        };

        if ($store->isDedicated()) {
            $table->index('owner_id', $index . '_owner_index');
            $table->foreign('owner_id', $index . '_owner_foreign')
                ->references($store->ownerKey)->on((string) $store->ownerTable)->cascadeOnDelete();
        } else {
            $table->index(['settable_type', 'settable_id'], $index . '_settable_index');
        }

        $table->index('category_id', $index . '_category_index');
        $table->index(['autoload', 'scope'], $index . '_autoload_index');
        $table->foreign(['category_id', 'category_store'], $index . '_category_foreign')
            ->references(['id', 'store'])->on($shape->names->categories())->nullOnDelete();
    }

    /** Who did what, when and through which channel. There is no value column. */
    private function createAudit(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->audit();

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($name, $shape): void {
            $table->char('id', 26)->primary();
            $this->tenant($table, $shape);
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
            $table->index([...$this->tenantColumns($shape), 'store', 'scope', 'key'], $name . '_subject_index');
            $table->index(['actor_alias', 'actor_id'], $name . '_actor_index');
        });
    }

    /**
     * One row per version of a setting, with the payload before and after exactly as the values
     * table stores it. `setting_ref` is a string so the table never depends on `ids.type`.
     */
    private function createHistory(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->history();
        $binary = $this->binaryCollation($connection);

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($binary, $name, $shape): void {
            $table->char('id', 26)->primary();

            if ($shape->tenancy === TenancyMode::Column) {
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

            $table->index([...$this->tenantColumns($shape), 'store', 'scope', 'key', 'locale', 'version'], $name . '_subject_index');
            $table->index('created_at', $name . '_created_index');
        });
    }

    /**
     * Writes waiting for approval. `payload` is stored as the values table would store it, and
     * `base_version` lets a request whose setting has since changed become `stale`.
     */
    private function createChangeRequests(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->changeRequests();
        $binary = $this->binaryCollation($connection);

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($binary, $name, $shape): void {
            $table->char('id', 26)->primary();

            if ($shape->tenancy === TenancyMode::Column) {
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

            $table->index([...$this->tenantColumns($shape), 'status', 'store'], $name . '_status_index');
            $table->index([...$this->tenantColumns($shape), 'store', 'scope', 'key', 'locale'], $name . '_subject_index');
            $table->index('expires_at', $name . '_expires_index');
        });
    }

    /** Store and layer entries edited through the API; `config` holds the entry as JSON. */
    private function createStores(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->stores();

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($name): void {
            $table->char('id', 26)->primary();
            $table->string('kind', 16);
            $table->string('name', 64);
            $table->json('config');
            $table->timestamps();

            $table->unique(['kind', 'name'], $name . '_kind_name_unique');
        });
    }

    /**
     * What to create on each connection, the default store's connection first.
     *
     * @return array<string, array{shared: bool, definitions: bool, stores: list<StoreSchema>}>
     */
    private function plan(): array
    {
        $shape = $this->shape();
        $main = $this->connectionOf($shape->store($this->primaryStore()));

        if ($this->only !== null) {
            $store = $shape->store(new StoreName($this->only));
            $connection = $this->pinnedConnection ?? $this->connectionOf($store);

            return [$connection => ['shared' => $this->pinnedConnection !== null, 'definitions' => false, 'stores' => [$store]]];
        }

        $plan = [$main => ['shared' => true, 'definitions' => $shape->definitionsTable, 'stores' => []]];

        foreach ($shape->stores as $store) {
            $connection = $this->connectionOf($store);
            $plan[$connection] ??= ['shared' => true, 'definitions' => false, 'stores' => []];
            $plan[$connection]['stores'][] = $store;
        }

        return $plan;
    }

    /**
     * Whether to create this table. A single store's copy skips a table that already exists,
     * because `migrate:fresh` replays both it and the full migration.
     */
    private function missing(string $connection, string $table): bool
    {
        return $this->only === null || ! Schema::connection($connection)->hasTable($table);
    }

    /**
     * A key column typed per `ids.type`. The primary key is an explicit command rather than the
     * fluent ->primary(), so PostgreSQL sees it before a self-referencing foreign key.
     */
    private function key(Blueprint $table, string $column, IdType $type, bool $primary = false): ColumnDefinition
    {
        if ($primary && $type === IdType::Bigint) {
            return $table->bigIncrements($column);
        }

        $definition = match ($type) {
            IdType::Bigint => $table->unsignedBigInteger($column),
            IdType::Uuid   => $table->char($column, 36),
            IdType::Ulid   => $table->char($column, 26),
        };

        if ($primary) {
            $table->primary($column);
        }

        return $definition;
    }

    /** A title, label or description: text, or a JSON map of locale to text. */
    private function translatable(Blueprint $table, string $column, SchemaShape $shape, bool $nullable = true): void
    {
        $definition = $shape->translations === TranslationsMode::Json
            ? $table->json($column)
            : ($column === 'title' ? $table->string($column) : $table->text($column));

        if ($nullable) {
            $definition->nullable();
        }
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

    /** The tenant column of column-mode tenancy: '' is the central partition. */
    private function tenant(Blueprint $table, SchemaShape $shape): void
    {
        if ($shape->tenancy === TenancyMode::Column) {
            $table->string('tenant', 64)->default('');
        }
    }

    /** @return list<string> */
    private function tenantColumns(SchemaShape $shape): array
    {
        return $shape->tenancy === TenancyMode::Column ? ['tenant'] : [];
    }

    private function primaryStore(): StoreName
    {
        if ($this->only !== null) {
            return new StoreName($this->only);
        }

        $default = config('laranail.settings.default', 'settings');

        return new StoreName(is_string($default) ? $default : 'settings');
    }

    private function connectionOf(StoreSchema $store): string
    {
        return $store->connection ?? DB::getDefaultConnection();
    }

    private function shape(): SchemaShape
    {
        return app(SchemaShapeRepository::class)->current();
    }
};
