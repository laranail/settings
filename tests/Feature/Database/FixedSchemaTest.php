<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Actions\FindForeignTables;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * The table shape is fixed; `tenancy.mode` is the only switch. The tenancy matrix runs one
 * scenario under `none` and `column`: on SQLite with the suite, and on MySQL and PostgreSQL in
 * the `database` CI job.
 */

it('creates every table, keyed by ULID, with a uid per row and a string owner key', function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);

    settings()->for(new OwnerRef('user', 42))->set('theme', 'dark');
    $row = DB::table('settings')->first();

    expect(array_map(Schema::hasTable(...), ['settings_categories', 'settings_definitions', 'settings', 'settings_audit', 'settings_history', 'settings_change_requests', 'settings_stores']))
        ->each->toBeTrue()
        ->and(strlen((string) $row->id))->toBe(26)
        ->and(strlen((string) $row->uid))->toBe(26)
        ->and($row->uid)->not->toBe($row->id)
        ->and($row->settable_type)->toBe('user')
        ->and($row->settable_id)->toBe('42')
        ->and(Schema::hasColumn('settings', 'slug'))->toBeFalse()
        ->and(Schema::hasColumn('settings', 'tenant'))->toBeFalse()
        ->and(DB::table('settings_history')->value('setting_ref'))->toBe($row->uid);
});

it('stores titles as per-locale JSON', function (): void {
    useDatabase();

    $mail = settings()->categories()->create(new NewCategory('mail', 'Mail', translations: ['fr' => 'Courrier']));

    expect(json_decode((string) DB::table('settings_categories')->value('title'), true))->toBe(['' => 'Mail', 'fr' => 'Courrier'])
        ->and($mail->titleIn('fr_CA'))->toBe('Courrier')
        ->and($mail->titleIn('de'))->toBe('Mail')
        ->and(fn (): NewCategory => new NewCategory('x', 'X', translations: ['fr' => ' ']))->toThrow(InvalidIdentifier::class);
});

it('prefixes every table and honours each override, including the foreign-table check', function (): void {
    useDatabase([
        'tables.prefix'         => 'app_',
        'tables.categories'     => 'setting_groups',
        'tables.audit'          => 'setting_log',
        'tables.definitions'    => 'setting_defs',
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

it('has doctor fail on a missing column and pass on a complete schema', function (): void {
    useDatabase();

    expect(Artisan::call('laranail::settings.doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('Every column is present.');

    Schema::table('settings_audit', static function (Blueprint $table): void {
        $table->dropIndex('settings_audit_actor_index');
        $table->dropColumn('actor_alias');
    });

    expect(Artisan::call('laranail::settings.doctor'))->toBe(1)
        ->and(Artisan::output())->toContain('settings_audit.actor_alias');
});

it('runs end to end under each tenancy mode', function (string $tenancy): void {
    $column = $tenancy === 'column';

    useDatabase([
        'tenancy.mode'                 => $tenancy,
        'stores.settings.owners'       => ['team'],
        'stores.settings.soft_deletes' => true,
        'stores.settings.inherits'     => true,
        'stores.user_settings'         => [
            'layers'   => ['database'],
            'owners'   => ['user'],
            'mode'     => 'dedicated',
            'owner'    => ['table' => 'users', 'type' => 'bigint'],
            'inherits' => true,
        ],
    ], stores: ['user_settings'], ownerTables: ['users' => 'bigint']);
    // Column-mode tenancy runs the whole scenario inside tenant `acme`.
    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): ?string => $column ? 'acme' : null));
    DB::table('users')->insert(['id' => 1, 'name' => 'Ada']);

    $user = new OwnerRef('user', 1);
    $team = new OwnerRef('team', 7);

    $categories = settings()->categories();
    $mail = $categories->create(new NewCategory('mail', 'Mail', translations: ['fr' => 'Courrier']));
    app(DefinitionRegistry::class)->define('mail.host')->inCategory('mail')->labelled('Host')->register();

    settings()->set('mail.host', 'smtp.acme.test');
    settings()->set('site.name', 'Acme');
    settings()->for($user)->set('theme', 'dark');
    settings()->for($team)->set('theme', 'dark');
    settings()->for($team)->forget('theme');
    settings()->for($team)->set('theme', 'light');
    settings()->for($team)->lock('theme');
    settings()->for($team)->unlock('theme');

    $synced = app(SyncDefinitions::class)->handle()['synced'];
    $trail = settings()->for($team)->history()->key('theme')->get();
    $request = settings()->for($team)->propose('theme', 'dark');
    $exit = Artisan::call('laranail::settings.doctor', ['--json' => true]);
    $report = Artisan::output();

    expect(settings()->get('mail.host'))->toBe('smtp.acme.test')
        ->and(settings()->for($team)->get('theme'))->toBe('light')
        ->and(settings()->for($user)->get('theme'))->toBe('dark')
        ->and(settings()->for($user)->get('site.name'))->toBe('Acme')
        ->and($synced)->toBe(1)
        ->and((int) DB::table('settings')->where('scope', 'team:7')->value('version'))->toBe(4)
        ->and(DB::table('user_settings')->value('owner_id'))->toBe(1)
        ->and(array_map(static fn (HistoryEntry $entry): string => $entry->operation->value . ':' . $entry->version, $trail))->toBe(['unlock:4', 'lock:3', 'set:2', 'forget:1', 'set:1'])
        ->and($request->scope)->toBe('team:7')
        ->and(DB::table('settings')->where('key', 'mail.host')->value('category_id'))->toBe($mail->id)
        ->and(settings()->audit()->get())->not->toBeEmpty()
        ->and(Schema::hasColumn('settings', 'tenant'))->toBe($column)
        ->and(Schema::hasColumn('settings_definitions', 'tenant'))->toBeFalse()
        ->and($column ? DB::table('settings_history')->distinct()->pluck('tenant')->all() : ['acme'])->toBe(['acme'])
        ->and($report)->toContain('Every column is present.')
        ->and($exit)->toBe(0);

    if (! $column) {
        return;
    }

    // Another tenant, in the next request, sees none of it.
    app()->forgetScopedInstances();
    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): string => 'globex'));

    expect(settings()->get('mail.host'))->toBeNull()
        ->and(settings()->categories()->all())->toBe([])
        ->and(settings()->audit()->get())->toBe([]);
})->with(['none', 'column'])->group('tenancy');
