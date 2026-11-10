<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Actions\FindForeignTables;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;

/** The values table's column names. */
function valueColumns(string $table = 'settings'): array
{
    return Schema::getColumnListing($table);
}

it('gives every row a shared slug and its own uid by default', function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);

    settings()->set('mail.from_address', 'a@acme.test');
    settings()->for(new OwnerRef('user', 1))->set('mail.from_address', 'b@acme.test');

    $rows = DB::table('settings')->orderBy('scope')->get();

    expect($rows->pluck('slug')->unique()->all())->toBe(['mail-from_address'])
        ->and($rows->pluck('uid')->unique())->toHaveCount(2);
});

it('gives each row a unique sha1 slug and no uid in slug_per_row mode', function (): void {
    useDatabase(['identity.mode' => 'slug_per_row', 'stores.settings.owners' => ['user']]);

    settings()->set('mail.from_address', 'a@acme.test');
    settings()->for(new OwnerRef('user', 1))->set('mail.from_address', 'b@acme.test');
    settings()->set('mail.from_address', 'c@acme.test');

    expect(valueColumns())->not->toContain('uid')
        ->and(DB::table('settings')->where('scope', 'global')->value('slug'))->toBe(sha1('global|mail.from_address|'))
        ->and(DB::table('settings')->where('scope', 'user:1')->value('slug'))->toBe(sha1('user:1|mail.from_address|'))
        ->and(settings()->get('mail.from_address'))->toBe('c@acme.test');

    $unique = collect(Schema::getIndexes('settings'))->filter(static fn (array $index): bool => $index['unique'] && $index['columns'] === ['slug']);

    expect($unique)->toHaveCount(1);
});

it('keeps only a uid in uid_only mode', function (): void {
    useDatabase(['identity.mode' => 'uid_only']);

    settings()->set('site.name', 'Acme');

    expect(valueColumns())->not->toContain('slug')
        ->and(strlen((string) DB::table('settings')->value('uid')))->toBe(26)
        ->and(settings()->get('site.name'))->toBe('Acme');
});

it('keys every package table by uuid or ulid, with matching category keys', function (string $type, int $length): void {
    useDatabase(['ids.type' => $type, 'definitions.table' => true]);

    $category = settings()->categories()->create(new NewCategory('mail', 'Mail'));
    settings()->configuration()->define('mail.host')->inCategory('mail')->register();
    settings()->set('mail.host', 'smtp.acme.test');

    $row = DB::table('settings')->first();

    expect(strlen((string) $category->id))->toBe($length)
        ->and(strlen((string) $row?->id))->toBe($length)
        ->and($row?->category_id)->toBe($category->id)
        ->and(settings()->get('mail.host'))->toBe('smtp.acme.test');
})->with([
    'uuid' => ['uuid', 36],
    'ulid' => ['ulid', 26],
]);

it('follows laranail.db-tools.id_type when ids.type is not set', function (): void {
    config()->set('laranail.db-tools.id_type', 'ULID');
    useDatabase();

    settings()->set('site.name', 'Acme');

    expect(app(SchemaShapeRepository::class)->current()->ids->value)->toBe('ulid')
        ->and(strlen((string) DB::table('settings')->value('id')))->toBe(26);
});

it('refuses an ids.type it does not know', function (): void {
    config()->set('laranail.settings.ids.type', 'serial');

    expect(fn () => app(SchemaShapeRepository::class)->configured())->toThrow(InvalidConfiguration::class, 'laranail.settings.ids.type');
});

it('types settable_id like the owners\' keys when ids.owner_key is typed', function (): void {
    useDatabase(['ids.owner_key' => 'typed', 'stores.settings.owners' => ['user']]);

    settings()->for(new OwnerRef('user', 42))->set('theme', 'dark');

    expect((int) DB::table('settings')->value('settable_id'))->toBe(42)
        ->and(settings()->for(new OwnerRef('user', 42))->get('theme'))->toBe('dark')
        ->and(fn () => settings()->for(new OwnerRef('user', 'abc'))->set('theme', 'light'))->toThrow(OwnerNotAllowed::class, 'bigint');
});

it('stores titles as per-locale JSON in json translations mode', function (): void {
    useDatabase(['translations.mode' => 'json']);

    $categories = settings()->categories();
    $categories->create(new NewCategory('mail', 'Mail', description: 'Outgoing mail', translations: ['fr' => 'Courrier', 'pt-br' => 'Correio']));
    $categories->rename('mail', 'Email');
    $mail = $categories->get('mail');
    $stored = json_decode((string) DB::table('settings_categories')->value('title'), true);

    expect($mail->title)->toBe('Email')
        ->and($mail->titleIn('fr_CA'))->toBe('Courrier')
        ->and($mail->titleIn('pt_BR'))->toBe('Correio')
        ->and($mail->titleIn('de'))->toBe('Email')
        ->and($mail->description)->toBe('Outgoing mail')
        ->and($stored)->toMatchArray(['' => 'Email', 'fr' => 'Courrier', 'pt_BR' => 'Correio']);
});

it('keeps plain titles in lang mode, where translations are not stored', function (): void {
    useDatabase();

    $mail = settings()->categories()->create(new NewCategory('mail', 'settings.categories.mail', translations: ['fr' => 'Courrier']));

    expect(DB::table('settings_categories')->value('title'))->toBe('settings.categories.mail')
        ->and($mail->titles)->toBe(['' => 'settings.categories.mail'])
        ->and(fn (): NewCategory => new NewCategory('x', 'X', translations: ['fr' => ' ']))->toThrow(InvalidIdentifier::class);
});

it('prefixes every table and honours each override, including the foreign-table check', function (): void {
    useDatabase([
        'tables.prefix'         => 'app_',
        'tables.categories'     => 'setting_groups',
        'tables.audit'          => 'setting_log',
        'tables.definitions'    => 'setting_defs',
        'definitions.table'     => true,
        'stores.settings.table' => 'preferences',
    ]);

    settings()->categories()->create(new NewCategory('mail', 'Mail'));
    settings()->set('site.name', 'Acme');

    expect(Schema::hasTable('app_preferences'))->toBeTrue()
        ->and(Schema::hasTable('app_setting_groups'))->toBeTrue()
        ->and(Schema::hasTable('app_setting_log'))->toBeTrue()
        ->and(Schema::hasTable('app_setting_defs'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeFalse()
        ->and(DB::table('app_preferences')->value('key'))->toBe('site.name')
        ->and(app(FindForeignTables::class)->handle())->toBe([]);

    // A foreign table under a prefixed name is still refused.
    Schema::drop('app_setting_log');
    Schema::create('app_setting_log', static function (Blueprint $table): void {
        $table->id();
        $table->string('message');
    });

    expect(app(FindForeignTables::class)->handle())->toBe([DB::getDefaultConnection() . '.app_setting_log']);
});

it('runs the definitions migration as a no-op while the table is off', function (): void {
    useDatabase();

    expect(Schema::hasTable('settings_definitions'))->toBeFalse();
});
