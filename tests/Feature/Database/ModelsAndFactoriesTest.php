<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Models\Setting;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Models\SettingAuditEntry;
use Simtabi\Laranail\Settings\Models\SettingDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/*
 * The Eloquent models and their factories: for tests, seeding and admin screens.
 * The engine never reads through them, but what they write must be what the engine reads.
 */

it('builds categories with store, system and parent states', function (): void {
    useDatabase(['stores.billing' => ['layers' => ['database']]], stores: ['billing']);

    $general = SettingCategory::factory()->system()->create(['slug' => 'general']);
    $mail = SettingCategory::factory()->parent($general)->titled('Mail', 'Outgoing mail')->create(['slug' => 'mail']);
    $billing = SettingCategory::factory()->forStore('billing')->create();

    expect($general->is_system)->toBeTrue()
        ->and(strlen($general->getKey()))->toBe(26)
        ->and($mail->parent?->is($general))->toBeTrue()
        ->and($general->children()->pluck('slug')->all())->toBe(['mail'])
        ->and(SettingCategory::query()->forStore('billing')->sole()->is($billing))->toBeTrue()
        // What the factory wrote is what the engine reads.
        ->and(settings()->categories()->get('mail')->parent?->value)->toBe('general')
        ->and(settings()->categories()->get('mail')->title)->toBe('Mail')
        ->and(settings()->categories()->get('general')->system)->toBeTrue();
});

it('writes JSON titles', function (): void {
    useDatabase();

    SettingCategory::factory()->titled('Mail')->create(['slug' => 'mail']);

    expect(settings()->categories()->get('mail')->title)->toBe('Mail');
});

it('builds values rows the engine reads', function (): void {
    useDatabase(['stores.settings.owners' => ['user'], 'stores.billing' => ['layers' => ['database']]], stores: ['billing']);
    $category = SettingCategory::factory()->create(['slug' => 'site']);

    $row = Setting::factory()->key('site.name')->value('Acme')->inCategory($category)->create();
    Setting::factory()->owner('user', 9)->key('ui.theme')->value('dark')->locked()->create();
    Setting::factory()->key('site.title')->locale('fr')->value('Bonjour')->create();
    Setting::factory()->forStore('billing')->key('billing.currency')->value('EUR')->create();

    expect($row->category?->is($category))->toBeTrue()
        ->and(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->for(new OwnerRef('user', 9))->get('ui.theme'))->toBe('dark')
        ->and(settings()->locale('fr')->get('site.title'))->toBe('Bonjour')
        ->and(settings()->store('billing')->get('billing.currency'))->toBe('EUR')
        ->and(Setting::forStore('billing')->pluck('key')->all())->toBe(['billing.currency'])
        ->and(Setting::forStore('billing')->first()?->storeName()->value)->toBe('billing')
        ->and(Setting::query()->count())->toBe(3)
        ->and(strlen($row->uid))->toBe(26);
});

it('hides trashed rows, as the engine does', function (): void {
    useDatabase(['stores.settings.soft_deletes' => true]);
    settings()->set('site.name', 'Acme');
    settings()->forget('site.name');

    expect(DB::table('settings')->count())->toBe(1)
        ->and(Setting::query()->count())->toBe(0);
});

it('builds definition and audit rows', function (): void {
    useDatabase(['definitions.sources' => ['code', 'config', 'database']]);

    $definition = SettingDefinition::factory()->key('mail.host')->system()->create();
    $entry = SettingAuditEntry::factory()->action(AuditAction::Forget, AuditOutcome::Denied)->by('user', 3)->at(now()->subDay())->create();

    expect($definition->is_system)->toBeTrue()
        ->and(SettingDefinition::query()->where('key', 'mail.host')->value('slug'))->toBe('mail-host')
        ->and(strlen($entry->id))->toBe(26)
        ->and(settings()->audit()->action(AuditAction::Forget)->get())->toHaveCount(1)
        ->and(settings()->audit()->get()[0]->actorId)->toBe('3');
});

it('refuses a store name it cannot turn into a table', function (): void {
    useDatabase();

    expect(fn (): Builder => Setting::forStore('Bad Store'))->toThrow(InvalidIdentifier::class)
        ->and(ModelContext::defaultStore()->value)->toBe('settings')
        ->and(ModelContext::hasTenantColumn())->toBeFalse()
        ->and(ModelContext::tenant())->toBe('');
});

it('says so when a model is used before the provider registered it', function (): void {
    $config = new ReflectionProperty(ModelContext::class, 'config');
    $saved = $config->getValue();
    $config->setValue(null, null);

    try {
        expect(ModelContext::names(...))->toThrow(InvalidConfiguration::class, 'before SettingsServiceProvider');
    } finally {
        $config->setValue(null, $saved);
    }
});
