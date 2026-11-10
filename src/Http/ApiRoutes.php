<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http;

use Illuminate\Routing\Router;
use Simtabi\Laranail\Settings\Enums\Capability;
use Simtabi\Laranail\Settings\Http\Controllers\AuditController;
use Simtabi\Laranail\Settings\Http\Middleware\FreshControllers;
use Simtabi\Laranail\Settings\Http\Middleware\IdempotentWrites;
use Simtabi\Laranail\Settings\Http\Controllers\StoresController;
use Simtabi\Laranail\Settings\Http\Controllers\ChangesController;
use Simtabi\Laranail\Settings\Http\Controllers\HistoryController;
use Simtabi\Laranail\Settings\Http\Controllers\SettingsController;
use Simtabi\Laranail\Settings\Http\Controllers\TransferController;
use Simtabi\Laranail\Settings\Http\Controllers\CategoriesController;
use Simtabi\Laranail\Settings\Http\Controllers\ConfigurationController;

/**
 * The REST API's routes (§16.10), registered only when `http.enabled` is on: under
 * `http.prefix`/v1, named `laranail-settings.api.<capability>` (the owner form of a settings route
 * adds `owners.`), through `http.middleware`, the `laranail-settings` rate limiter and, on writes,
 * the idempotency middleware. Groups listed false in `http.routes` are left out.
 */
final readonly class ApiRoutes
{
    public const string NAME_PREFIX = 'laranail-settings.api.';

    /**
     * Every route: [method, uri under /v1, capability, controller, controller method].
     *
     * @var list<array{string, string, Capability, class-string, string}>
     */
    private const array ROUTES = [
        ['GET', 'stores', Capability::StoresIndex, StoresController::class, 'index'],
        ['GET', 'stores/{store}/definitions', Capability::DefinitionsIndex, StoresController::class, 'definitions'],
        ['GET', '{owner}settings', Capability::SettingsIndex, SettingsController::class, 'index'],
        ['GET', '{owner}settings/{key}', Capability::SettingsShow, SettingsController::class, 'show'],
        ['PUT', '{owner}settings/{key}', Capability::SettingsUpdate, SettingsController::class, 'update'],
        ['DELETE', '{owner}settings/{key}', Capability::SettingsDestroy, SettingsController::class, 'destroy'],
        ['POST', '{owner}settings/{key}/lock', Capability::SettingsLock, SettingsController::class, 'lock'],
        ['DELETE', '{owner}settings/{key}/lock', Capability::SettingsUnlock, SettingsController::class, 'unlock'],
        ['GET', '{owner}settings/{key}/explain', Capability::SettingsExplain, SettingsController::class, 'explain'],
        ['GET', '{owner}settings/{key}/history', Capability::SettingsHistory, HistoryController::class, 'index'],
        ['POST', '{owner}settings/{key}/rollback', Capability::SettingsRollback, HistoryController::class, 'rollback'],
        ['GET', 'audit', Capability::AuditIndex, AuditController::class, 'index'],
        ['GET', 'stores/{store}/changes', Capability::ChangesIndex, ChangesController::class, 'index'],
        ['POST', 'stores/{store}/changes', Capability::ChangesStore, ChangesController::class, 'store'],
        ['GET', 'stores/{store}/changes/{id}', Capability::ChangesShow, ChangesController::class, 'show'],
        ['POST', 'stores/{store}/changes/{id}/approve', Capability::ChangesApprove, ChangesController::class, 'approve'],
        ['POST', 'stores/{store}/changes/{id}/reject', Capability::ChangesReject, ChangesController::class, 'reject'],
        ['POST', 'stores/{store}/changes/{id}/cancel', Capability::ChangesCancel, ChangesController::class, 'cancel'],
        ['GET', 'stores/{store}/categories', Capability::CategoriesIndex, CategoriesController::class, 'index'],
        ['POST', 'stores/{store}/categories', Capability::CategoriesStore, CategoriesController::class, 'store'],
        ['PATCH', 'stores/{store}/categories/{slug}', Capability::CategoriesUpdate, CategoriesController::class, 'update'],
        ['DELETE', 'stores/{store}/categories/{slug}', Capability::CategoriesDestroy, CategoriesController::class, 'destroy'],
        ['GET', 'configuration', Capability::ConfigurationIndex, ConfigurationController::class, 'index'],
        ['PUT', 'configuration/{kind}/{name}', Capability::ConfigurationUpdate, ConfigurationController::class, 'update'],
        ['DELETE', 'configuration/{kind}/{name}', Capability::ConfigurationDestroy, ConfigurationController::class, 'destroy'],
        ['PUT', 'stores/{store}/definitions/{key}', Capability::DefinitionsUpdate, ConfigurationController::class, 'updateDefinition'],
        ['DELETE', 'stores/{store}/definitions/{key}', Capability::DefinitionsDestroy, ConfigurationController::class, 'destroyDefinition'],
        ['POST', 'stores/{store}/export', Capability::TransferExport, TransferController::class, 'export'],
        ['POST', 'stores/{store}/import', Capability::TransferImport, TransferController::class, 'import'],
        ['POST', 'stores/{store}/diff', Capability::TransferDiff, TransferController::class, 'diff'],
    ];

    /**
     * @return list<array{method: string, uri: string, name: string, capability: Capability, controller: class-string, action: string}>
     */
    public static function definitions(): array
    {
        $routes = [];

        foreach (self::ROUTES as [$method, $uri, $capability, $controller, $action]) {
            $forms = str_starts_with($uri, '{owner}')
                ? ['' => 'stores/{store}/', 'owners.' => 'stores/{store}/owners/{alias}/{id}/']
                : ['' => ''];

            foreach ($forms as $prefix => $path) {
                $routes[] = [
                    'method'     => $method,
                    'uri'        => $path . str_replace('{owner}', '', $uri),
                    'name'       => self::NAME_PREFIX . $prefix . $capability->value,
                    'capability' => $capability,
                    'controller' => $controller,
                    'action'     => $action,
                ];
            }
        }

        return $routes;
    }

    /**
     * @param list<string> $middleware
     * @param array<array-key, mixed> $groups `http.routes`
     */
    public static function register(Router $router, string $prefix, array $middleware, array $groups): void
    {
        $router->prefix(trim($prefix, '/') . '/v1')
            ->middleware([FreshControllers::class, ...$middleware, 'throttle:laranail-settings'])
            ->group(static function (Router $router) use ($groups): void {
                foreach (self::definitions() as $route) {
                    if (($groups[$route['capability']->group()] ?? true) === false) {
                        continue;
                    }

                    $registered = $router->addRoute($route['method'], $route['uri'], [$route['controller'], $route['action']])
                        ->name($route['name'])
                        ->where(['store' => '[a-z][a-z0-9_]*', 'key' => '[A-Za-z0-9_.]+', 'alias' => '[a-z][a-z0-9_]*', 'kind' => 'stores|layers']);

                    if ($route['method'] !== 'GET') {
                        $registered->middleware(IdempotentWrites::class);
                    }
                }
            });
    }
}
