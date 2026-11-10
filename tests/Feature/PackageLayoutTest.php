<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;

/*
 * The conventional package layout: routes/api.php and routes/web.php behind http.enabled,
 * resources/lang/en/settings.php under `laranail/settings`, and the alert mail view.
 */

/** @return list<array<string, mixed>> the `laranail-settings.*` rows `route:list --json` prints */
function listedSettingsRoutes(): array
{
    Artisan::call('route:list', ['--name' => 'laranail-settings', '--json' => true]);
    $rows = json_decode(trim(Artisan::output()), true);

    return is_array($rows) ? array_values($rows) : [];
}

it('lists the 39 API routes from routes/api.php when the API is enabled', function (): void {
    $this->rebootWith(['laranail.settings.http.enabled' => true, 'laranail.settings.http.middleware' => ['api', 'auth']]);

    $listed = listedSettingsRoutes();
    $names = array_column($listed, 'name');
    sort($names);
    $settings = ['index', 'show', 'explain', 'update', 'destroy', 'lock', 'unlock', 'history', 'rollback'];
    $expected = [
        'audit.index', 'categories.destroy', 'categories.index', 'categories.store', 'categories.update',
        'changes.approve', 'changes.cancel', 'changes.index', 'changes.reject', 'changes.show', 'changes.store',
        'configuration.destroy', 'configuration.index', 'configuration.update',
        'definitions.destroy', 'definitions.index', 'definitions.update', 'stores.index',
        'transfer.diff', 'transfer.export', 'transfer.import',
        ...array_map(static fn (string $action): string => 'settings.' . $action, $settings),
        ...array_map(static fn (string $action): string => 'owners.settings.' . $action, $settings),
    ];
    $expected = array_map(static fn (string $name): string => 'laranail-settings.api.' . $name, $expected);
    sort($expected);

    expect($listed)->toHaveCount(39)
        ->and($names)->toBe($expected)
        ->and(array_unique(array_map(static fn (array $row): string => explode('/v1/', (string) $row['uri'])[0], $listed)))->toBe(['api/settings'])
        ->and(app(Router::class)->getRoutes()->getByName('laranail-settings.api.stores.index')?->middleware())->toContain('api', 'auth', 'throttle:laranail-settings');
});

it('moves every route under http.prefix, and mounts them at /v1 when it is empty', function (string $prefix, string $base): void {
    $this->rebootWith(['laranail.settings.http.enabled' => true, 'laranail.settings.http.middleware' => ['api', 'auth'], 'laranail.settings.http.prefix' => $prefix]);

    $uris = array_map(static fn (array $row): string => (string) $row['uri'], listedSettingsRoutes());

    expect($uris)->toHaveCount(39)
        ->and(array_filter($uris, static fn (string $uri): bool => ! str_starts_with($uri, $base . '/')))->toBe([])
        ->and(route('laranail-settings.api.settings.show', ['store' => 'settings', 'key' => 'site.name']))->toBe(url($base . '/stores/settings/settings/site.name'))
        ->and(route('laranail-settings.api.owners.settings.show', ['store' => 'settings', 'alias' => 'user', 'id' => 1, 'key' => 'theme']))->toBe(url($base . '/stores/settings/owners/user/1/settings/theme'));
})->with([
    'the default'      => ['api/settings', 'api/settings/v1'],
    'a custom prefix'  => ['/internal/config/', 'internal/config/v1'],
    'no prefix at all' => ['', 'v1'],
]);

it('lists no settings route when the API is disabled', function (): void {
    $this->rebootWith(['laranail.settings.http.enabled' => false]);

    expect(listedSettingsRoutes())->toBe([]);
});

it('ships a web route file that registers nothing and is not loaded', function (): void {
    $this->rebootWith(['laranail.settings.http.enabled' => true]);
    $router = new Router(new Dispatcher);

    require dirname(__DIR__, 2) . '/routes/web.php';

    expect(is_file(dirname(__DIR__, 2) . '/routes/web.php'))->toBeTrue()
        ->and($router->getRoutes()->getRoutes())->toBe([])
        ->and(array_filter(listedSettingsRoutes(), static fn (array $row): bool => str_starts_with((string) $row['name'], 'laranail-settings.web.')))->toBe([]);
});

/**
 * Every message key the package's source reads (quoted `problems.`, `alert.` and `console.` dot
 * paths), plus the problem titles, which Problems reads by type.
 *
 * @return list<string>
 */
function messageKeysUsedInSource(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $keys = [];
    $files = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $files++;
        $source = (string) file_get_contents($file->getPathname());
        // Crude on purpose: any quoted dot path under a top-level section of the file counts as
        // read, which errs toward "used" (a ternary picking one of two keys is covered).
        preg_match_all("/'(?:laranail\\/settings::settings\\.)?((?:problems|alert|console)\\.[a-z0-9_.-]*[a-z0-9])'/", $source, $matches);
        $keys = [...$keys, ...$matches[1]];
    }

    expect($files)->toBeGreaterThanOrEqual(355);

    $problems = (string) file_get_contents($root . '/Http/Support/Problems.php');
    preg_match_all("/this->make\\(\\d+, '([a-z-]+)'/", $problems, $fixed);
    $types = [...problemTypes(), SettingsException::TYPE, ...$fixed[1]];

    foreach ($types as $type) {
        $keys[] = 'problems.titles.' . $type;
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

/**
 * Every leaf key of the packaged English file, as a dot path.
 *
 * @param array<array-key, mixed> $lines
 *
 * @return list<string>
 */
function messageKeysInFile(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $name => $line) {
        $keys = is_array($line)
            ? [...$keys, ...messageKeysInFile($line, $prefix . $name . '.')]
            : [...$keys, $prefix . $name];
    }

    sort($keys);

    return $keys;
}

it('has every message key the source reads in the lang file, and no other', function (): void {
    $used = messageKeysUsedInSource();
    $shipped = messageKeysInFile(require dirname(__DIR__, 2) . '/resources/lang/en/settings.php');

    // Floor: 85 keys read today (28 problem titles, 5 problem details, 11 alert lines, 41 console
    // lines; counted 2026-11-10). A scan that suddenly finds fewer has broken.
    expect(count($used))->toBeGreaterThanOrEqual(85)
        ->and(array_values(array_diff($used, $shipped)))->toBe([], 'read in src/ but missing from resources/lang/en/settings.php')
        ->and(array_values(array_diff($shipped, $used)))->toBe([], 'in resources/lang/en/settings.php but read nowhere in src/');
});

it('registers the translations and the mail view under laranail/settings, publishably', function (): void {
    $groups = ServiceProvider::publishableGroups();

    expect(app('translator')->get('laranail/settings::settings.problems.validation'))->toBe('The request is invalid.')
        ->and(view()->exists('laranail/settings::mail.alert'))->toBeTrue()
        ->and($groups)->toContain('laranail::settings-translations', 'laranail::settings-views')
        ->and(array_keys(ServiceProvider::pathsToPublish(SettingsServiceProvider::class, 'laranail::settings-translations')))->not->toBeEmpty()
        ->and(array_keys(ServiceProvider::pathsToPublish(SettingsServiceProvider::class, 'laranail::settings-views')))->not->toBeEmpty();
});

it('reads a published translation in place of the packaged English', function (): void {
    app('translator')->addLines(['settings.problems.validation' => 'Die Anfrage ist ungültig.'], 'de', 'laranail/settings');
    app()->setLocale('de');

    expect(__('laranail/settings::settings.problems.validation'))->toBe('Die Anfrage ist ungültig.')
        ->and(__('laranail/settings::settings.problems.validation', locale: 'en'))->toBe('The request is invalid.')
        ->and(trans_choice('laranail/settings::settings.console.history_pruned', 1, locale: 'en'))->toBe('Removed 1 history entry.')
        ->and(trans_choice('laranail/settings::settings.console.history_pruned', 0, locale: 'en'))->toBe('Removed 0 history entries.');
});
