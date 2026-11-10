<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Http\ApiRoutes;
use Simtabi\Laranail\Settings\Enums\Capability;

/*
 * §16.2 (D-17): every REST capability runs the action the PHP API runs. Two guards: each
 * controller method delegates to an Actions\* class it was given, and the routes, the Capability
 * enum and the controllers name the same capabilities, each through the actions the enum lists.
 */

/** @return array<string, ReflectionMethod> "Controller::method" => method, for every public controller method */
function controllerMethods(): array
{
    $methods = [];

    foreach (glob(dirname(__DIR__, 2) . '/src/Http/Controllers/*.php') ?: [] as $file) {
        $class = new ReflectionClass('Simtabi\\Laranail\\Settings\\Http\\Controllers\\' . basename($file, '.php'));

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() !== '__construct' && $method->getDeclaringClass()->getName() === $class->getName()) {
                $methods[$class->getShortName() . '::' . $method->getName()] = $method;
            }
        }
    }

    return $methods;
}

/** @return list<class-string> the Actions\* classes a controller method calls through its constructor properties */
function delegatedActions(ReflectionMethod $method): array
{
    $lines = file((string) $method->getFileName()) ?: [];
    $body = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    $actions = [];

    foreach ($method->getDeclaringClass()->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $class = $type instanceof ReflectionNamedType ? $type->getName() : '';

        if (str_starts_with($class, 'Simtabi\\Laranail\\Settings\\Actions\\') && preg_match('/\$this->' . $parameter->getName() . '->(handle|paginate|get|cursor)\(/', $body) === 1) {
            $actions[] = $class;
        }
    }

    return $actions;
}

it('makes every controller method delegate to an action', function (): void {
    $methods = controllerMethods();
    $idle = array_keys(array_filter($methods, static fn (ReflectionMethod $method): bool => delegatedActions($method) === []));

    expect(count($methods))->toBeGreaterThanOrEqual(30)
        ->and($idle)->toBe([]);
});

it('holds the routes, the capabilities and the controllers to each other', function (): void {
    $routes = ApiRoutes::definitions();
    $methods = controllerMethods();
    $routed = array_values(array_unique(array_map(static fn (array $route): string => $route['capability']->value, $routes)));
    $mismatched = [];

    foreach ($routes as $route) {
        $method = $methods[new ReflectionClass($route['controller'])->getShortName() . '::' . $route['action']] ?? null;

        if (! $method instanceof ReflectionMethod || array_intersect($route['capability']->actions(), delegatedActions($method)) === []) {
            $mismatched[] = $route['name'];
        }
    }

    sort($routed);
    $cases = array_map(static fn (Capability $capability): string => $capability->value, Capability::cases());
    sort($cases);

    expect(count($routes))->toBeGreaterThanOrEqual(39)
        ->and($routed)->toBe($cases)
        ->and($mismatched)->toBe([])
        // Every controller method is routed: nothing reachable only by accident.
        ->and(array_diff(array_keys($methods), array_map(static fn (array $route): string => new ReflectionClass($route['controller'])->getShortName() . '::' . $route['action'], $routes)))->toBe([]);
});

it('names every route with the vendor-scoped prefix and a capability', function (): void {
    foreach (ApiRoutes::definitions() as $route) {
        expect($route['name'])->toStartWith('laranail-settings.api.')
            ->and(Capability::tryFrom((string) preg_replace('/^laranail-settings\.api\.(owners\.)?/', '', $route['name'])))->toBe($route['capability']);
    }
});
