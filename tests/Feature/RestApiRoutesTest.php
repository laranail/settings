<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/*
 * §16.2 (D-17): every REST route runs the action the PHP API runs. Read from the live router:
 * each route names a controller method, each controller method is routed, and each delegates
 * to an Actions\* class it was handed as a parameter.
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

/** @return list<class-string> the Actions\* classes a controller method is handed and calls */
function delegatedActions(ReflectionMethod $method): array
{
    $lines = file((string) $method->getFileName()) ?: [];
    $body = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    $actions = [];

    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();
        $class = $type instanceof ReflectionNamedType ? $type->getName() : '';

        if (str_starts_with($class, 'Simtabi\\Laranail\\Settings\\Actions\\') && preg_match('/\$' . $parameter->getName() . '->(handle|paginate|get|cursor)\(/', $body) === 1) {
            $actions[] = $class;
        }
    }

    return $actions;
}

/** @return list<Route> the API's routes, as the application registered them */
function apiRoutes(): array
{
    return array_values(array_filter(app(Router::class)->getRoutes()->getRoutes(), static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'laranail-settings.api.')));
}

beforeEach(function (): void {
    $this->rebootWith(['laranail.settings.http.enabled' => true, 'laranail.settings.http.middleware' => ['api', 'auth']]);
});

it('makes every controller method delegate to an action it is handed', function (): void {
    $methods = controllerMethods();
    $idle = array_keys(array_filter($methods, static fn (ReflectionMethod $method): bool => delegatedActions($method) === []));

    expect(count($methods))->toBeGreaterThanOrEqual(30)
        ->and($idle)->toBe([]);
});

it('routes every controller method, and every route to a controller method that delegates', function (): void {
    $routes = apiRoutes();
    $methods = controllerMethods();
    $routed = [];

    foreach ($routes as $route) {
        [$controller, $action] = explode('@', $route->getActionName());
        $key = new ReflectionClass($controller)->getShortName() . '::' . $action;
        $routed[] = $key;

        expect($methods[$key] ?? null)->toBeInstanceOf(ReflectionMethod::class, sprintf('%s names no controller method', $route->getName()));
    }

    expect(count($routes))->toBeGreaterThanOrEqual(39)
        ->and(array_values(array_diff(array_keys($methods), $routed)))->toBe([]);
});

it('names every route with the vendor-scoped prefix, once', function (): void {
    $names = array_map(static fn (Route $route): string => (string) $route->getName(), apiRoutes());

    expect(count($names))->toBeGreaterThanOrEqual(39)
        ->and(array_unique($names))->toHaveCount(count($names))
        ->and(array_filter($names, static fn (string $name): bool => preg_match('/^laranail-settings\.api\.(owners\.)?[a-z]+\.[a-z]+$/', $name) !== 1))->toBe([]);
});
