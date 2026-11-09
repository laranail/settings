<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Tests\Fixtures\SettingsOwner;
use Simtabi\Laranail\Settings\Tests\Fixtures\SoftDeletingOwner;

beforeEach(function (): void {
    useDatabase([
        'stores.settings.owners' => ['owner', 'soft'],
        'stores.teams'           => ['layers' => ['database'], 'owners' => ['team']],
    ], stores: ['teams']);

    Schema::create('owners', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('team_id')->nullable();
    });
    Schema::create('soft_owners', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->softDeletes();
    });

    Relation::morphMap(['owner' => SettingsOwner::class, 'soft' => SoftDeletingOwner::class], false);
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('gives a model a settings handle keyed by its morph alias', function (): void {
    $owner = SettingsOwner::query()->create();
    $owner->settings()->set('ui.theme', 'dark');

    expect($owner->settings()->get('ui.theme'))->toBe('dark')
        ->and(DB::table('settings')->value('scope'))->toBe('owner:' . $owner->id)
        ->and($owner->settingsOwner()->alias)->toBe('owner');
});

it('refuses a model without a morph map entry, whose alias would be a class name (G-6)', function (): void {
    Relation::morphMap([], false);

    expect(fn () => SettingsOwner::query()->create()->settingsOwner())->toThrow(InvalidIdentifier::class);
});

it('deletes an owner\'s settings with the owner, and keeps them through a soft delete (G-7)', function (): void {
    $owner = SettingsOwner::query()->create();
    $owner->settings()->set('ui.theme', 'dark');
    $soft = SoftDeletingOwner::query()->create();
    $soft->settings()->set('ui.theme', 'light');

    $owner->delete();
    $soft->delete();

    expect(DB::table('settings')->pluck('scope')->all())->toBe(['soft:' . $soft->id]);

    $soft->forceDelete();

    expect(DB::table('settings')->count())->toBe(0);
});

it('ignores deleting a model whose alias no store holds', function (): void {
    Relation::morphMap(['stranger' => SettingsOwner::class], false);
    $owner = SettingsOwner::query()->create();

    expect($owner->delete())->toBeTrue();
});

it('eager-loads a page of owners in one query per store, then reads them with none (G-9)', function (): void {
    config()->set('laranail.settings.stores.settings.inherits', true);
    app()->forgetScopedInstances();
    $owners = collect(range(1, 5))->map(static fn (int $i): SettingsOwner => SettingsOwner::query()->create(['team_id' => 7]));
    $owners->each(static fn (SettingsOwner $owner) => $owner->settings()->set('ui.theme', 'theme-' . $owner->id));
    settings()->for(new OwnerRef('team', 7))->set('ui.density', 'compact');

    DB::enableQueryLog();
    $loaded = SettingsOwner::query()->withSettings()->get();
    $queries = count(DB::getQueryLog());

    $values = $loaded->map(static fn (SettingsOwner $owner): array => [$owner->settings()->get('ui.theme'), $owner->settings()->get('ui.density')]);

    // One query for the owners, one per store (settings for the owners, teams for the parent).
    expect($queries)->toBe(3)
        ->and(count(DB::getQueryLog()))->toBe(3)
        ->and($values->first())->toBe(['theme-' . $owners->first()?->id, 'compact']);

    $loaded->first()?->settings()->set('ui.theme', 'changed');

    expect($loaded->first()?->settings()->get('ui.theme'))->toBe('changed');
});

it('finds and prunes the settings of owners deleted in bulk', function (): void {
    $kept = SettingsOwner::query()->create();
    $gone = SettingsOwner::query()->create();
    $kept->settings()->set('ui.theme', 'dark');
    $gone->settings()->set('ui.theme', 'dark');
    $trashed = SoftDeletingOwner::query()->create();
    $trashed->settings()->set('ui.theme', 'dark');
    $trashed->delete();
    settings()->for(new OwnerRef('owner', 'not-a-number'))->set('ui.theme', 'x');
    SettingsOwner::query()->whereKey($gone->id)->delete();
    config()->set('laranail.settings.stores.teams.owners', ['team']);

    $this->artisan('laranail::settings.prune-orphans', ['--dry-run' => true])
        ->expectsOutputToContain('owner:' . $gone->id)
        ->expectsOutputToContain('Alias [team] has no model in the morph map')
        ->expectsOutputToContain('2 orphaned owner(s) found; nothing removed (dry run).')
        ->assertSuccessful();

    expect(DB::table('settings')->count())->toBe(4);

    $this->artisan('laranail::settings.prune-orphans')->expectsOutputToContain('Removed 2 row(s) of 2 orphaned owner(s).')->assertSuccessful();
    $this->artisan('laranail::settings.prune-orphans')->expectsOutputToContain('No orphaned settings.')->assertSuccessful();

    expect(DB::table('settings')->pluck('scope')->sort()->values()->all())->toBe(['owner:' . $kept->id, 'soft:' . $trashed->id]);
});
