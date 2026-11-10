<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;

beforeEach(function (): void {
    useDatabase(['stores.settings.soft_deletes' => true, 'stores.settings.owners' => ['user']]);
});

it('trashes a forgotten row instead of deleting it, and reads past it', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->forget('site.name');

    expect(settings()->has('site.name'))->toBeFalse()
        ->and(DB::table('settings')->count())->toBe(1)
        ->and(DB::table('settings')->value('deleted_at'))->not->toBeNull();
});

it('restores a trashed row on the next set, continuing its version, never as a conflict', function (): void {
    $audit = auditInMemory();
    settings()->set('site.name', 'One');
    settings()->set('site.name', 'Two');
    settings()->forget('site.name');

    settings()->set('site.name', 'Three');
    $row = DB::table('settings')->first();

    expect(settings()->get('site.name'))->toBe('Three')
        ->and(DB::table('settings')->count())->toBe(1)
        ->and($row?->deleted_at)->toBeNull()
        ->and((int) $row?->version)->toBe(3)
        ->and(collect($audit->entries())->where('action', AuditAction::Update)->last()?->versionTo)->toBe(3)
        ->and(collect($audit->entries())->where('outcome', AuditOutcome::Failed))->toBeEmpty();
});

it('does not bring back the lock or labels of the forgotten row', function (): void {
    settings()->set('site.name', 'One');
    settings()->lock('site.name');
    DB::table('settings')->update(['label' => 'Stale', 'meta' => '{"x":1}']);
    settings()->unlock('site.name');
    settings()->lock('site.name');
    DB::table('settings')->update(['deleted_at' => now()]);

    settings()->set('site.name', 'Two');
    $row = DB::table('settings')->first();

    expect((bool) $row?->is_locked)->toBeFalse()
        ->and($row?->label)->toBeNull()
        ->and($row?->meta)->toBeNull()
        ->and(settings()->get('site.name'))->toBe('Two');
});

it('locks a trashed key by restoring its row as a value-less lock', function (): void {
    settings()->set('site.name', 'One');
    settings()->forget('site.name');

    settings()->lock('site.name');

    expect(DB::table('settings')->count())->toBe(1)
        ->and(DB::table('settings')->value('deleted_at'))->toBeNull()
        ->and((int) DB::table('settings')->value('version'))->toBe(2)
        ->and(settings()->has('site.name'))->toBeFalse();
});

it('trashes a whole scope on flush, and does not list it as an owner any more', function (): void {
    $owner = new OwnerRef('user', 7);
    settings()->for($owner)->set('theme', 'dark');
    settings()->for($owner)->set('locale', 'fr');

    settings()->for($owner)->flush();
    settings()->for($owner)->set('theme', 'light');

    expect(DB::table('settings')->whereNotNull('deleted_at')->count())->toBe(1)
        ->and(settings()->for($owner)->get('theme'))->toBe('light')
        ->and(settings()->for($owner)->has('locale'))->toBeFalse();
});

it('deletes a category for good, so creating its slug again makes a new one', function (): void {
    $categories = settings()->categories();
    $first = $categories->create(new NewCategory('mail', 'Mail'));
    $categories->delete('mail');

    expect(fn (): Category => $categories->get('mail'))->toThrow(UnknownCategory::class)
        ->and(DB::table('settings_categories')->count())->toBe(0);

    $again = $categories->create(new NewCategory('mail', 'Email'));

    expect($again->id)->not->toBe($first->id)
        ->and($again->title)->toBe('Email')
        ->and(DB::table('settings_categories')->count())->toBe(1);
});

it('does not link a setting to a trashed category', function (): void {
    settings()->categories()->create(new NewCategory('mail', 'Mail'));
    settings()->categories()->delete('mail');
    settings()->configuration()->define('mail.host')->inCategory('mail')->register();

    settings()->set('mail.host', 'smtp.acme.test');

    expect(DB::table('settings')->value('category_id'))->toBeNull();
});
