<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategoryDeleted;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;

beforeEach(function (): void {
    useDatabase(['stores.billing' => ['layers' => ['database']]], stores: ['billing']);
});

it('creates, renames, nests and lists categories of a store', function (): void {
    Event::fake([CategorySaved::class]);
    $categories = settings()->categories();

    $mail = $categories->create(new NewCategory('mail', 'Mail', description: 'Outgoing mail', icon: 'envelope', sortOrder: 2));
    $categories->create(new NewCategory('smtp', 'SMTP', parent: 'mail'));
    $categories->rename('mail', 'Email');

    expect($mail->slug->value)->toBe('mail')
        ->and($mail->store->value)->toBe('settings')
        ->and($mail->icon)->toBe('envelope')
        ->and($categories->get('mail')->title)->toBe('Email')
        ->and($categories->get('smtp')->parent?->value)->toBe('mail')
        ->and(array_map(static fn (Category $category): string => $category->slug->value, $categories->all()))->toBe(['smtp', 'mail']);

    Event::assertDispatchedTimes(CategorySaved::class, 3);
});

it('moves a category, and refuses a move under itself or a descendant', function (): void {
    $categories = settings()->categories();
    $categories->create(new NewCategory('a', 'A'));
    $categories->create(new NewCategory('b', 'B', parent: 'a'));
    $categories->create(new NewCategory('c', 'C', parent: 'b'));

    expect(fn () => $categories->move('a', 'c'))->toThrow(CategoryCycle::class, 'cannot move under [c]')
        ->and(fn () => $categories->move('a', 'a'))->toThrow(CategoryCycle::class);

    $categories->move('c', 'a');
    $categories->move('b', null);

    expect($categories->get('c')->parent?->value)->toBe('a')
        ->and($categories->get('b')->parent)->toBeNull();
});

it('refuses a parent from another store and reports unknown categories', function (): void {
    settings()->store('billing')->categories()->create(new NewCategory('tax', 'Tax'));
    $categories = settings()->categories();

    expect(fn (): Category => $categories->create(new NewCategory('vat', 'VAT', parent: 'tax')))->toThrow(CategoryStoreMismatch::class, 'belongs to store [billing]')
        ->and(fn (): Category => $categories->get('tax'))->toThrow(CategoryStoreMismatch::class)
        ->and(fn (): Category => $categories->get('nope'))->toThrow(UnknownCategory::class)
        ->and(fn () => $categories->rename('nope', 'x'))->toThrow(UnknownCategory::class)
        ->and(fn (): NewCategory => new NewCategory('Bad Slug', 'x'))->toThrow(InvalidIdentifier::class)
        ->and(fn (): NewCategory => new NewCategory('ok', ' '))->toThrow(InvalidIdentifier::class)
        ->and(fn (): NewCategory => new NewCategory('ok', 'x', sortOrder: -1))->toThrow(InvalidIdentifier::class);
});

it('deletes a category, lifting its children and unlinking its settings', function (): void {
    Event::fake([CategoryDeleted::class]);
    $categories = settings()->categories();
    $categories->create(new NewCategory('mail', 'Mail'));
    $categories->create(new NewCategory('smtp', 'SMTP', parent: 'mail'));
    app(DefinitionRegistry::class)->define('mail.from')->inCategory('mail')->register();
    settings()->set('mail.from', 'ap@acme.test');

    $categories->delete('mail');

    expect(fn (): Category => $categories->get('mail'))->toThrow(UnknownCategory::class)
        ->and($categories->get('smtp')->parent)->toBeNull()
        ->and(DB::table('settings')->value('category_id'))->toBeNull()
        ->and(settings()->get('mail.from'))->toBe('ap@acme.test');

    Event::assertDispatched(CategoryDeleted::class);
});

it('needs manage_categories to change categories and view to read them, and audits the change', function (): void {
    expect(fn (): Category => settings()->as(user())->categories()->create(new NewCategory('mail', 'Mail')))->toThrow(AccessDenied::class, '[manage_categories]')
        ->and(fn (): array => settings()->as(user())->categories()->all())->toThrow(AccessDenied::class, '[view]');

    allowGates(Ability::ManageCategories, Ability::View);
    settings()->as(user())->categories()->create(new NewCategory('mail', 'Mail'));

    $audit = DB::table('settings_audit')->where('action', 'manage_categories')->orderBy('occurred_at')->get();

    expect($audit->pluck('outcome')->all())->toBe(['denied', 'allowed'])
        ->and(json_decode((string) $audit->last()?->meta, true))->toBe(['operation' => 'create', 'category' => 'mail'])
        ->and(settings()->as(user())->categories()->all())->toHaveCount(1);
});

it('audits a failed change and keeps nothing of it', function (): void {
    $categories = settings()->categories();
    $categories->create(new NewCategory('mail', 'Mail'));

    expect(fn () => $categories->move('mail', 'nope'))->toThrow(UnknownCategory::class)
        ->and(DB::table('settings_audit')->where('outcome', 'failed')->count())->toBe(1);
});

it('needs a database layer for categories', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['runtime']);
    app()->forgetScopedInstances();

    expect(fn (): Category => settings()->categories()->create(new NewCategory('mail', 'Mail')))->toThrow(StoreNotWritable::class)
        ->and(fn (): array => settings()->categories()->all())->toThrow(StoreNotWritable::class);
});
