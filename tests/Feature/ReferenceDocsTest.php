<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Routing\Route as RoutingRoute;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Facades\Settings as SettingsFacade;

/*
 * The reference pages are held to the code they describe: each guard reads the live source of
 * truth (the contract, the registered routes, the event and exception directories, the registered
 * commands, the shipped config), asserts it inspected at least a floor of items, and fails when
 * one of them is missing from its page.
 */

function referencePage(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/docs/' . $path);
}

/** @return list<string> class basenames in a src directory */
function classesIn(string $directory): array
{
    $names = array_map(static fn (string $file): string => basename($file, '.php'), glob(dirname(__DIR__, 2) . '/src/' . $directory . '/*.php') ?: []);
    sort($names);

    return $names;
}

/**
 * The shipped config's keys as configuration.md names them: a store or layer name becomes
 * `<name>`, an `http.routes` entry `<group>`, and a list or an open map is one key.
 *
 * @param array<array-key, mixed> $config
 *
 * @return array<string, mixed> key => default
 */
function documentedConfigKeys(array $config, string $prefix = ''): array
{
    $keys = [];
    $leaves = ['seed.categories', 'authorization.permissions', 'readiness.flush_on', 'importers', 'alerts.rules', 'definitions.sources', 'definitions.allowed_rules'];

    foreach ($config as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        $path = (string) preg_replace(['/^stores\.[a-z_]+/', '/^layers\.[a-z_]+/', '/^http\.routes\.[a-z_]+/'], ['stores.<name>', 'layers.<name>', 'http.routes.<group>'], $path);

        if (is_array($value) && ! array_is_list($value) && $value !== [] && ! in_array($path, $leaves, true)) {
            $keys += documentedConfigKeys($value, $path);

            continue;
        }

        $keys[$path] = $value;
    }

    return $keys;
}

it('documents every method of the Settings contract, and the facade proxies each one', function (): void {
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), new ReflectionClass(Settings::class)->getMethods());
    $page = referencePage('tools/settings-api.md');
    $facade = (string) new ReflectionClass(SettingsFacade::class)->getDocComment();

    expect(count($methods))->toBeGreaterThanOrEqual(47);

    foreach ($methods as $method) {
        expect(str_contains($page, '`' . $method . '()`'))->toBeTrue(sprintf('settings-api.md does not document %s()', $method))
            ->and(preg_match('/@method static [^\n]+ ' . $method . '\(/', $facade))->toBe(1, sprintf('the facade docblock does not list %s()', $method));
    }
});

it('documents every REST route and every problem type', function (): void {
    $this->rebootWith(['laranail.settings.http.enabled' => true]);
    $routes = array_filter(Route::getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'laranail-settings.api.'));
    $page = referencePage('tools/rest-api-reference.md');
    $types = problemTypes();

    expect(count($routes))->toBeGreaterThanOrEqual(39)
        ->and(count($types))->toBeGreaterThanOrEqual(24);

    foreach ($routes as $route) {
        $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
        $uri = (string) preg_replace('#^api/settings/v1/#', '', $route->uri());
        $name = (string) preg_replace('/^laranail-settings\.api\.(owners\.)?/', '', (string) $route->getName());

        expect(str_contains($page, sprintf('`%s /%s`', $method, $uri)))->toBeTrue(sprintf('rest-api-reference.md does not document %s /%s', $method, $uri))
            ->and(str_contains($page, '`' . $name . '`'))->toBeTrue(sprintf('rest-api-reference.md does not name route %s', $name));
    }

    foreach ($types as $type) {
        expect(str_contains($page, '| `' . $type . '` |'))->toBeTrue(sprintf('rest-api-reference.md does not list problem type %s', $type));
    }
});

it('documents every event', function (): void {
    $events = classesIn('Events');
    $page = referencePage('tools/events.md');

    expect(count($events))->toBeGreaterThanOrEqual(30);

    foreach ($events as $event) {
        expect(str_contains($page, '`' . $event . '`'))->toBeTrue(sprintf('events.md does not document %s', $event));
    }
});

it('documents every exception', function (): void {
    $exceptions = classesIn('Exceptions');
    $page = referencePage('tools/exceptions.md');

    expect(count($exceptions))->toBeGreaterThanOrEqual(30);

    foreach ($exceptions as $exception) {
        expect(str_contains($page, '| `' . $exception . '` |'))->toBeTrue(sprintf('exceptions.md does not document %s', $exception));
    }
});

it('documents every console command', function (): void {
    $commands = array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'laranail::settings.')));
    $page = referencePage('tools/commands.md');

    expect(count($commands))->toBeGreaterThanOrEqual(17);

    foreach ($commands as $command) {
        expect(preg_match('/`' . preg_quote($command, '/') . '[ `]/', $page))->toBe(1, sprintf('commands.md does not document %s', $command));
    }
});

it('documents every shipped config key with its default', function (): void {
    /** @var array<array-key, mixed> $config */
    $config = require dirname(__DIR__, 2) . '/config/settings.php';
    $keys = documentedConfigKeys($config);
    $rows = array_filter(explode("\n", referencePage('configuration.md')), static fn (string $line): bool => str_starts_with($line, '| `'));
    // A default that is resolved later, or that differs by store or layer, is documented in words.
    $describedDefaults = ['schema.path', 'stores.<name>.layers', 'seed.categories', 'definitions.allowed_rules', 'readiness.flush_on', 'importers', 'authorization.permissions', 'definitions.sources', 'configuration.sources', 'audit.sinks', 'verification.abilities'];

    expect(count($keys))->toBeGreaterThanOrEqual(80);

    foreach ($keys as $key => $default) {
        $row = array_values(array_filter($rows, static fn (string $line): bool => str_contains(explode('|', $line)[1] ?? '', '`' . $key . '`')))[0] ?? null;

        expect($row)->not->toBeNull(sprintf('configuration.md does not document %s', $key));

        if (str_contains($key, '<') || in_array($key, $describedDefaults, true) || is_array($default)) {
            continue;
        }

        $shown = match (true) {
            $default === null => '`null`',
            is_bool($default) => $default ? '`true`' : '`false`',
            $default === ''   => "`''`",
            default           => '`' . $default . '`',
        };

        expect(str_contains((string) $row, $shown))->toBeTrue(sprintf('configuration.md gives %s a default other than %s', $key, $shown));
    }
});
