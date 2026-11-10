<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Actions\PruneAudit;
use Simtabi\Laranail\Settings\Events\AuditPruned;
use Simtabi\Laranail\Settings\Events\CategoryMoved;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategoryRenamed;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Events\DefinitionsSynced;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * The events Phase 4 added for maintenance and categories: dispatched once the change is made,
 * carrying counts and identifiers only.
 */

it('announces a category move and a rename besides CategorySaved', function (): void {
    useDatabase();
    Event::fake([CategorySaved::class, CategoryMoved::class, CategoryRenamed::class]);
    $categories = settings()->categories();

    $categories->create(new NewCategory('mail', 'Mail'));
    $categories->create(new NewCategory('smtp', 'SMTP'));
    $categories->move('smtp', 'mail');
    $categories->move('smtp', null);
    $categories->rename('mail', 'Email');

    Event::assertDispatchedTimes(CategorySaved::class, 5);
    Event::assertDispatched(CategoryMoved::class, static fn (CategoryMoved $event): bool => $event->slug === 'smtp' && $event->parent === 'mail' && $event->store === 'settings');
    Event::assertDispatched(CategoryMoved::class, static fn (CategoryMoved $event): bool => $event->parent === null);
    Event::assertDispatched(CategoryRenamed::class, static fn (CategoryRenamed $event): bool => $event->slug === 'mail');
});

it('announces a definitions sync with its counts', function (): void {
    useDatabase(['definitions.table' => true]);
    Event::fake([DefinitionsSynced::class]);
    app(DefinitionRegistry::class)->define('site.name')->register();
    app(DefinitionRegistry::class)->define('site.url')->register();

    app(SyncDefinitions::class)->handle();

    Event::assertDispatched(DefinitionsSynced::class, static fn (DefinitionsSynced $event): bool => $event->synced === 2 && $event->removed === 0 && $event->conflicts === 0 && $event->skipped === 0);
});

it('announces an audit prune with what it removed and the period', function (): void {
    useDatabase();
    Event::fake([AuditPruned::class]);
    $this->travelTo(now()->subDays(40));
    settings()->set('site.name', 'Acme');
    $this->travelBack();

    app(PruneAudit::class)->handle(30);

    Event::assertDispatched(AuditPruned::class, static fn (AuditPruned $event): bool => $event->removed === 1 && $event->keepDays === 30);
});
