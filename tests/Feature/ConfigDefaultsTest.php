<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;
use Simtabi\Laranail\Settings\Support\ConfigDefaults;

it('fills the keys a partial section leaves out from the shipped defaults', function (): void {
    $merged = ConfigDefaults::merge(
        ['tables' => ['prefix' => 'laranail_', 'audit' => 'settings_audit'], 'cache' => ['ttl' => 3600, 'stale' => 300]],
        ['tables' => ['prefix' => 'acme_'], 'cache' => ['ttl' => 60]],
    );

    expect($merged)->toBe([
        'tables' => ['prefix' => 'acme_', 'audit' => 'settings_audit'],
        'cache' => ['ttl' => 60, 'stale' => 300],
    ]);
});

it('keeps lists and application-named maps exactly as the application wrote them', function (): void {
    $merged = ConfigDefaults::merge(
        [
            'stores' => ['settings' => ['layers' => ['database', 'runtime', 'shipped']]],
            'layers' => ['database' => ['driver' => 'database'], 'runtime' => ['driver' => 'json']],
            'importers' => ['toolkit' => 'A', 'spatie' => 'B'],
            'seed' => ['categories' => ['general' => ['title' => 'General']], 'enabled' => true],
            'definitions' => ['sources' => ['code', 'config']],
        ],
        [
            'stores' => ['teams' => ['layers' => ['runtime']]],
            'layers' => ['runtime' => ['driver' => 'json']],
            'importers' => ['custom' => 'C'],
            'seed' => ['categories' => ['billing' => ['title' => 'Billing']]],
            'definitions' => ['sources' => ['code']],
        ],
    );

    expect($merged['stores'])->toBe(['teams' => ['layers' => ['runtime']]])
        ->and($merged['layers'])->toBe(['runtime' => ['driver' => 'json']])
        ->and($merged['importers'])->toBe(['custom' => 'C'])
        ->and($merged['seed'])->toBe(['categories' => ['billing' => ['title' => 'Billing']], 'enabled' => true])
        ->and($merged['definitions'])->toBe(['sources' => ['code']]);
});

it('lets a scalar or null from the application replace a default section', function (): void {
    expect(ConfigDefaults::merge(['http' => ['routes' => ['stores' => true]]], ['http' => ['routes' => false]]))
        ->toBe(['http' => ['routes' => false]]);
});

it('applies the merge to the registered configuration', function (): void {
    config()->set('laranail.settings.tables', ['prefix' => 'acme_']);

    $provider = app()->getProvider(SettingsServiceProvider::class);
    new ReflectionMethod($provider, 'mergeNestedConfigDefaults')->invoke($provider);

    expect(config('laranail.settings.tables.prefix'))->toBe('acme_')
        ->and(config('laranail.settings.tables.audit'))->toBe('settings_audit')
        ->and(config('laranail.settings.tables.categories'))->toBe('settings_categories');
});
