<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

function useDedicatedStore(array $extra = []): void
{
    useDatabase([
        'stores.settings.owners' => ['team'],
        'stores.user_settings'   => [
            'layers' => ['database'],
            'owners' => ['user'],
            'mode'   => 'dedicated',
            'owner'  => ['table' => 'users'],
        ] + $extra,
    ], stores: ['user_settings'], ownerTables: ['users' => 'bigint']);

    DB::table('users')->insert([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]);
}

it('replaces settable_* with a non-null owner_id foreign key (D-3)', function (): void {
    useDedicatedStore();

    settings()->for(new OwnerRef('user', 1))->set('theme', 'dark');

    expect(Schema::getColumnListing('user_settings'))->toContain('owner_id')
        ->and(Schema::getColumnListing('user_settings'))->not->toContain('settable_type')
        ->and((int) DB::table('user_settings')->value('owner_id'))->toBe(1)
        ->and(DB::table('user_settings')->value('scope'))->toBe('user:1')
        ->and(settings()->for(new OwnerRef('user', 1))->get('theme'))->toBe('dark')
        ->and(collect(Schema::getForeignKeys('user_settings'))->pluck('foreign_table')->all())->toContain('users');
});

it('holds owner rows only: a global write is refused and names the fallback store (D-3)', function (): void {
    useDedicatedStore();

    expect(fn () => settings()->store('user_settings')->set('theme', 'dark'))->toThrow(OwnerNotAllowed::class, 'store [settings]')
        ->and(DB::table('user_settings')->count())->toBe(0);
});

it('reads its global value from the fallback store when owners inherit (§7.2)', function (): void {
    useDedicatedStore(['inherits' => true]);

    settings()->set('theme', 'light');
    settings()->for(new OwnerRef('user', 2))->set('theme', 'dark');

    expect(settings()->for(new OwnerRef('user', 1))->get('theme'))->toBe('light')
        ->and(settings()->for(new OwnerRef('user', 2))->get('theme'))->toBe('dark')
        ->and(settings()->store('user_settings')->get('theme'))->toBe('light')
        ->and(settings()->for(new OwnerRef('user', 1))->explain('theme')->steps)->toContain('settings / global / (none) / database');
});

it('removes an owner\'s rows with the owner, through the cascading foreign key', function (): void {
    useDedicatedStore();
    settings()->for(new OwnerRef('user', 1))->set('theme', 'dark');

    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    DB::table('users')->where('id', 1)->delete();

    expect(DB::table('user_settings')->count())->toBe(0);
});

it('refuses a dedicated store without one owner alias, an owner table or a polymorphic fallback', function (array $store, string $message): void {
    config()->set('laranail.settings.stores.user_settings', $store + ['layers' => ['runtime'], 'mode' => 'dedicated']);

    expect(fn () => app(StoreRegistry::class)->all())->toThrow(InvalidConfiguration::class, $message);
})->with([
    'no owner alias'     => [['owners' => [], 'owner' => ['table' => 'users']], 'exactly one owner alias'],
    'two owner aliases'  => [['owners' => ['user', 'admin'], 'owner' => ['table' => 'users']], 'exactly one owner alias'],
    'no owner table'     => [['owners' => ['user']], 'owner.table'],
    'itself as fallback' => [['owners' => ['user'], 'owner' => ['table' => 'users'], 'fallback' => 'user_settings'], 'must be another store'],
    'unknown fallback'   => [['owners' => ['user'], 'owner' => ['table' => 'users'], 'fallback' => 'nowhere'], 'must be a configured polymorphic store'],
    'bad owner key type' => [['owners' => ['user'], 'owner' => ['table' => 'users', 'type' => 'serial']], '`type` one of'],
    'unknown mode'       => [['mode' => 'shared'], 'use [polymorphic] or [dedicated]'],
]);
