<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

function databaseDriver(bool $writable = true): DatabaseDriver
{
    $driver = app(DriverFactory::class)->make(new LayerConfig('database', 'database', $writable));
    assert($driver instanceof DatabaseDriver);

    return $driver;
}

beforeEach(function (): void {
    useDatabase();
});

it('stores a value as a row with its uid, group and version', function (): void {
    settings()->set('mail.from_address', 'ap@acme.test');

    $row = DB::table('settings')->first();

    expect(settings()->get('mail.from_address'))->toBe('ap@acme.test')
        ->and($row?->scope)->toBe('global')
        ->and($row?->group)->toBe('mail')
        ->and(strlen((string) $row?->uid))->toBe(26)
        ->and((int) $row?->version)->toBe(1)
        ->and($row?->settable_type)->toBeNull()
        ->and($row?->settable_id)->toBeNull();

    settings()->set('mail.from_address', 'billing@acme.test');

    expect((int) DB::table('settings')->value('version'))->toBe(2)
        ->and(DB::table('settings')->count())->toBe(1);
});

it('keeps one global row per key, because scope is never null', function (): void {
    settings()->set('site.name', 'One');
    settings()->set('site.name', 'Two');

    expect(DB::table('settings')->where('key', 'site.name')->count())->toBe(1)
        ->and(DB::table('settings')->where('key', 'site.name')->value('scope'))->toBe('global');
});

it('identifies a row by scope, key and locale, never by group', function (): void {
    $unique = collect(Schema::getIndexes('settings'))->filter(static fn (array $index): bool => $index['unique'] && ! $index['primary']);

    expect($unique->pluck('columns')->all())->toContain(['scope', 'key', 'locale'])
        ->and($unique->pluck('columns')->flatten()->all())->not->toContain('group');
});

it('keeps the identity index inside the utf8mb4 byte budget, tenant column included', function (): void {
    $migration = (string) file_get_contents(dirname(__DIR__, 3) . '/database/migrations/create_laranail_settings_tables.php');
    $values = substr($migration, (int) strpos($migration, 'private function valuesTable'), 4000);
    preg_match_all("/string\\('(tenant|scope|key|locale)', (\\d+)\\)/", $values, $matches);
    $lengths = array_combine($matches[1], array_map(intval(...), $matches[2]));
    ksort($lengths);

    expect($lengths)->toBe(['key' => 191, 'locale' => 16, 'scope' => 128, 'tenant' => 64])
        ->and(array_sum($lengths) * 4)->toBeLessThan(3072);
});

it('treats keys case-sensitively in the table and lower-cases them in the API', function (): void {
    $now = now();
    $row = static fn (string $key, string $uid): array => ['id' => (string) Str::ulid(), 'scope' => 'global', 'key' => $key, 'locale' => '', 'group' => 'x', 'uid' => $uid, 'value' => '1', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];

    DB::table('settings')->insert($row('Theme', str_repeat('A', 26)));
    DB::table('settings')->insert($row('theme', str_repeat('B', 26)));

    settings()->set('THEME', 'dark');

    expect(DB::table('settings')->count())->toBe(2)
        ->and(settings()->get('theme'))->toBe('dark');
});

it('stores owner rows by scope with the owner id as a string, and reads them back', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    app()->forgetScopedInstances();

    settings()->for(new OwnerRef('user', 42))->set('ui.theme', 'dark');

    $row = DB::table('settings')->where('scope', 'user:42')->first();

    expect($row?->settable_type)->toBe('user')
        ->and($row?->settable_id)->toBe('42')
        ->and(settings()->for(new OwnerRef('user', 42))->get('ui.theme'))->toBe('dark')
        ->and(settings()->get('ui.theme'))->toBeNull();
});

it('keeps a lock on a key without a value as a row with no value', function (): void {
    settings()->lock('site.name');

    $row = DB::table('settings')->first();

    expect($row?->value)->toBeNull()
        ->and((bool) $row?->is_locked)->toBeTrue()
        ->and(settings()->has('site.name'))->toBeFalse();

    settings()->unlock('site.name');

    expect((bool) DB::table('settings')->value('is_locked'))->toBeFalse();
});

it('raises ConcurrentModification when the row changed after it was read', function (): void {
    $driver = databaseDriver();
    $store = new StoreName('settings');
    $record = static fn (int $version): Record => new Record(Scope::global(), new Key('site.name'), Locale::none(), '"x"', version: $version);

    $driver->put($store, $record(1));
    $driver->put($store, $record(2));

    // A second writer that also read version 1 writes version 2 again.
    expect(fn () => $driver->put($store, $record(2)))->toThrow(ConcurrentModification::class, 'expected version 1')
        ->and((int) DB::table('settings')->value('version'))->toBe(2)
        // A first write racing another first write hits the unique index.
        ->and(fn () => $driver->put($store, $record(1)))->toThrow(ConcurrentModification::class);
});

it('refuses writes to a read-only database layer', function (): void {
    $driver = databaseDriver(writable: false);

    expect($driver->isWritable())->toBeFalse()
        ->and(fn () => $driver->put(new StoreName('settings'), new Record(Scope::global(), new Key('a'), Locale::none(), '1')))->toThrow(StoreNotWritable::class)
        ->and(fn () => $driver->forget(new StoreName('settings'), Scope::global(), new Key('a'), Locale::none()))->toThrow(StoreNotWritable::class);
});

it('refuses a group longer than the column', function (): void {
    expect(fn () => settings()->set(str_repeat('g', 65) . '.key', 1))->toThrow(InvalidSettingValue::class, 'longer than 64');
});

it('links a setting to its category in the same store and refuses one from another store', function (): void {
    $now = now();
    $id = (string) Str::ulid();
    DB::table('settings_categories')->insert(['id' => $id, 'store' => 'settings', 'title' => '{"":"Mail"}', 'slug' => 'mail', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('settings_categories')->insert(['id' => (string) Str::ulid(), 'store' => 'billing', 'title' => '{"":"Tax"}', 'slug' => 'tax', 'created_at' => $now, 'updated_at' => $now]);

    $registry = app(DefinitionRegistry::class);
    $registry->define('mail.from')->inCategory('mail')->register();
    $registry->define('tax.rate')->inCategory('tax')->register();
    $registry->define('misc.flag')->inCategory('nowhere')->register();

    settings()->set('mail.from', 'ap@acme.test');
    settings()->set('misc.flag', true);

    expect(DB::table('settings')->where('key', 'mail.from')->value('category_id'))->toBe($id)
        ->and(DB::table('settings')->where('key', 'mail.from')->value('category_store'))->toBe('settings')
        ->and(DB::table('settings')->where('key', 'misc.flag')->value('category_id'))->toBeNull()
        ->and(fn () => settings()->set('tax.rate', 16))->toThrow(CategoryStoreMismatch::class, 'belongs to store [billing]')
        ->and(DB::table('settings')->where('key', 'tax.rate')->exists())->toBeFalse();
});

it('raises DriverUnavailable when the table is missing on a live install', function (): void {
    Schema::drop('settings');

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(DriverUnavailable::class, 'table [settings] is missing')
        ->and(fn () => settings()->set('site.name', 'x'))->toThrow(DriverUnavailable::class);
});

it('raises DriverFailure, not a PDO exception, for any other database error', function (): void {
    Schema::table('settings', static function ($table): void {
        $table->dropColumn('is_public');
    });

    // A write names the column, which no database tolerates (SQLite reads an unknown quoted
    // identifier in a SELECT as a string literal, so a read would not notice).
    expect(fn () => settings()->set('site.name', 'Acme'))->toThrow(DriverFailure::class, 'database layer on connection');
});

it('names the table after the prefix and the store, and validates table names', function (): void {
    expect(databaseDriver()->tableFor(new StoreName('billing')))->toBe('billing')
        ->and(fn () => app(DriverFactory::class)->make(new LayerConfig('d', 'database', true, ['table' => 'Bad Name'])))
        ->toThrow(InvalidConfiguration::class, 'laranail.settings.layers.d.table')
        ->and(fn () => app(DriverFactory::class)->make(new LayerConfig('d', 'database', true, ['connection' => ''])))
        ->toThrow(InvalidConfiguration::class, '`connection` and `table`');
});

it('reads a store from its configured table', function (): void {
    useDatabase(['stores.settings.table' => 'app_settings']);

    settings()->set('site.name', 'Acme');

    expect(DB::table('app_settings')->value('key'))->toBe('site.name')
        ->and(Schema::hasTable('settings'))->toBeFalse();
});
