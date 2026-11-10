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
 * laranail/settings: every table the package uses, in dependency order.
 *
 *   1. categories    shared by the stores on a connection; nested by parent_id
 *   2. definitions   optional (definitions.table), on the default store's connection
 *   3. values        one table per database store
 *   4. audit         actions and denials, never values
 *   5. history       every value by version (history.enabled), encrypted values still encrypted
 *
 * The shape (key type, identity mode, owner keys, dedicated stores, soft deletes, translations,
 * tenancy, table names, connections) comes from the recorded schema file
 * (database/settings-schema.php, written by `install` and `make-store`), or from configuration
 * when nothing is recorded. Column types are explicit, so db-tools' blueprint macros cannot retype
 * them. `down()` drops the same tables in reverse order.
 *
 * `laranail::settings.make-store` copies this file for one new store by rewriting the $only line
 * (and, with --connection, the $pinnedConnection line): the copy creates that store's values table
 * and, on a connection of its own, that connection's categories, audit and history tables.
 */
return new class extends Migration
{
    /** null: every recorded database store plus the shared tables; a store name: that store only. */
    private ?string $only = null;

    private ?string $pinnedConnection = null;

    public function getConnection(): ?string
    {
        return $this->pinnedConnection ?? $this->shape()->store($this->primaryStore())->connection;
    }

    public function up(): void
    {
        foreach ($this->plan() as $connection => $tables) {
            $shape = $this->shape();

            if ($tables['shared'] && ($this->only === null || ! Schema::connection($connection)->hasTable($shape->names->categories()))) {
                $this->createCategories($connection, $shape);
            }

            if ($tables['definitions']) {
                $this->createDefinitions($connection, $shape);
            }

            foreach ($tables['stores'] as $store) {
                // A store's own copy skips a table the full migration already created (a
                // `migrate:fresh` replays both: the full one creates every recorded store).
                if ($this->only === null || ! Schema::connection($connection)->hasTable($shape->names->store($store->name, $store->table))) {
                    $this->createValues($connection, $shape, $store);
                }
            }

            if ($tables['shared'] && ($this->only === null || ! Schema::connection($connection)->hasTable($shape->names->audit()))) {
                $this->createAudit($connection, $shape);
            }

            if ($tables['shared'] && $shape->historyTable && ($this->only === null || ! Schema::connection($connection)->hasTable($shape->names->history()))) {
                $this->createHistory($connection, $shape);
            }
        }
    }

    public function down(): void
    {
        $shape = $this->shape();

        foreach (array_reverse($this->plan(), true) as $connection => $tables) {
            $schema = Schema::connection($connection);

            if ($tables['shared'] && $shape->historyTable) {
                $schema->dropIfExists($shape->names->history());
            }

            if ($tables['shared']) {
                $schema->dropIfExists($shape->names->audit());
            }

            foreach (array_reverse($tables['stores']) as $store) {
                $schema->dropIfExists($shape->names->store($store->name, $store->table));
            }

            if ($tables['definitions']) {
                $schema->dropIfExists($shape->names->definitions());
            }

            if ($tables['shared']) {
                $schema->dropIfExists($shape->names->categories());
            }
        }
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
     * One store's values. Identity is (tenant, scope, key, locale); those columns are
     * binary-collated so `Theme` and `theme`, or `pt_BR` and `pt_br`, cannot become one row on
     * MySQL only (G-38).
     */
    private function createValues(string $connection, SchemaShape $shape, StoreSchema $store): void
    {
        $name = $shape->names->store($store->name, $store->table);
        $categories = $shape->names->categories();
        $index = substr($name, 0, 40);
        $binary = $this->binaryCollation($connection);

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($binary, $categories, $index, $shape, $store): void {
            $collate = static fn (ColumnDefinition $column): ColumnDefinition => $binary === null ? $column : $column->collation($binary);

            $this->key($table, 'id', $shape->ids, primary: true);

            if ($shape->tenancy === TenancyMode::Column) {
                $collate($table->string('tenant', 64)->default(''));
            }

            if ($store->isDedicated()) {
                $this->key($table, 'owner_id', $store->ownerKeyType);
            } else {
                $table->string('settable_type', 64)->nullable();
                $shape->ownerKey === OwnerKeyType::Typed
                    ? $this->key($table, 'settable_id', $shape->ownerKeyType)->nullable()
                    : $table->string('settable_id', 36)->nullable();
            }

            $collate($table->string('scope', 128));
            $this->key($table, 'category_id', $shape->ids)->nullable();
            $table->string('category_store', 64)->nullable();
            $table->string('group', 64);
            $collate($table->string('key', 191));

            if ($shape->identity->hasSlug()) {
                $collate($table->string('slug', $shape->identity === IdentityMode::SlugPerRow ? 40 : 191)->nullable());
            }

            if ($shape->identity->hasUid()) {
                $table->char('uid', 26)->nullable();
            }

            $collate($table->string('locale', 16)->default(''));
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
                ->references(['id', 'store'])->on($categories)->nullOnDelete();
        });
    }

    /** The audit trail (§16.8): who did what, when and through which channel. No value column. */
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
     * The value history (§4.3): one row per version of a setting, with the payload before and
     * after exactly as the values table stores it (an encrypted value stays encrypted). Queried
     * by (store, scope, key, locale), so a forget followed by a set keeps one trail; `setting_ref`
     * is the row's uid (or id), kept as a string so the table never depends on `ids.type`.
     * Identity columns are binary-collated like the values table's.
     */
    private function createHistory(string $connection, SchemaShape $shape): void
    {
        $name = $shape->names->history();
        $binary = $this->binaryCollation($connection);

        Schema::connection($connection)->create($name, function (Blueprint $table) use ($binary, $name, $shape): void {
            $collate = static fn (ColumnDefinition $column): ColumnDefinition => $binary === null ? $column : $column->collation($binary);

            $table->char('id', 26)->primary();

            if ($shape->tenancy === TenancyMode::Column) {
                $collate($table->string('tenant', 64)->default(''));
            }

            $table->string('store', 64);
            $table->string('setting_ref', 36)->nullable();
            $collate($table->string('scope', 128));
            $collate($table->string('key', 191));
            $collate($table->string('locale', 16)->default(''));
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
     * A key column typed per `ids.type`. The primary key is an explicit command, not the fluent
     * ->primary(): Laravel appends fluent indexes after every other command, so PostgreSQL would
     * see a self-referencing foreign key before the key it references (SQLSTATE 42830).
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

    /** A title, label or description: text, or a JSON map of locale to text (D-8). */
    private function translatable(Blueprint $table, string $column, SchemaShape $shape, bool $nullable = true): void
    {
        $definition = $shape->translations === TranslationsMode::Json
            ? $table->json($column)
            : ($column === 'title' ? $table->string($column) : $table->text($column));

        if ($nullable) {
            $definition->nullable();
        }
    }

    /** The binary collation of the identity columns on this connection's driver (G-38). */
    private function binaryCollation(string $connection): ?string
    {
        return match (DB::connection($connection)->getDriverName()) {
            'mysql', 'mariadb' => 'utf8mb4_bin',
            'pgsql'            => 'C',
            default            => null,
        };
    }

    /** The tenant column of column-mode tenancy: '' is the central (no tenant) partition. */
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
