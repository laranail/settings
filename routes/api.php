<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Settings\Http\Controllers\AuditController;
use Simtabi\Laranail\Settings\Http\Middleware\IdempotentWrites;
use Simtabi\Laranail\Settings\Http\Controllers\StoresController;
use Simtabi\Laranail\Settings\Http\Controllers\ChangesController;
use Simtabi\Laranail\Settings\Http\Controllers\HistoryController;
use Simtabi\Laranail\Settings\Http\Controllers\SettingsController;
use Simtabi\Laranail\Settings\Http\Controllers\TransferController;
use Simtabi\Laranail\Settings\Http\Controllers\CategoriesController;
use Simtabi\Laranail\Settings\Http\Controllers\ConfigurationController;

/*
 * The REST API, loaded only when `laranail.settings.http.enabled` is on. The provider wraps it in
 * the `http.prefix`/v1 prefix, the `laranail-settings.api.` name prefix, the configured middleware
 * and rate limiter. Each `http.routes.<group>` switch can turn a group off.
 */

$enabled = static fn (string $group): bool => config('laranail.settings.http.routes.' . $group, true) !== false;

// The same setting routes, globally and for an owner.
$settings = static function () use ($enabled): void {
    Route::prefix('settings')->name('settings.')->group(static function () use ($enabled): void {
        if ($enabled('settings')) {
            Route::controller(SettingsController::class)->group(static function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('{key}', 'show')->name('show');
                Route::get('{key}/explain', 'explain')->name('explain');

                Route::middleware(IdempotentWrites::class)->group(static function (): void {
                    Route::put('{key}', 'update')->name('update');
                    Route::delete('{key}', 'destroy')->name('destroy');
                    Route::post('{key}/lock', 'lock')->name('lock');
                    Route::delete('{key}/lock', 'unlock')->name('unlock');
                });
            });
        }

        if ($enabled('history')) {
            Route::controller(HistoryController::class)->group(static function (): void {
                Route::get('{key}/history', 'index')->name('history');
                Route::post('{key}/rollback', 'rollback')->middleware(IdempotentWrites::class)->name('rollback');
            });
        }
    });
};

Route::prefix('stores')->group(static function () use ($enabled, $settings): void {
    if ($enabled('stores')) {
        Route::get('/', [StoresController::class, 'index'])->name('stores.index');
    }

    Route::prefix('{store}')->group(static function () use ($enabled, $settings): void {
        $settings();

        Route::prefix('owners/{alias}/{id}')->name('owners.')->group($settings);

        Route::prefix('definitions')->name('definitions.')->group(static function () use ($enabled): void {
            if ($enabled('definitions')) {
                Route::get('/', [StoresController::class, 'definitions'])->name('index');
            }

            if ($enabled('configuration')) {
                Route::controller(ConfigurationController::class)->middleware(IdempotentWrites::class)->group(static function (): void {
                    Route::put('{key}', 'updateDefinition')->name('update');
                    Route::delete('{key}', 'destroyDefinition')->name('destroy');
                });
            }
        });

        if ($enabled('approvals')) {
            Route::controller(ChangesController::class)->prefix('changes')->name('changes.')->group(static function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('{id}', 'show')->name('show');

                Route::middleware(IdempotentWrites::class)->group(static function (): void {
                    Route::post('/', 'store')->name('store');
                    Route::post('{id}/approve', 'approve')->name('approve');
                    Route::post('{id}/reject', 'reject')->name('reject');
                    Route::post('{id}/cancel', 'cancel')->name('cancel');
                });
            });
        }

        if ($enabled('categories')) {
            Route::controller(CategoriesController::class)->prefix('categories')->name('categories.')->group(static function (): void {
                Route::get('/', 'index')->name('index');

                Route::middleware(IdempotentWrites::class)->group(static function (): void {
                    Route::post('/', 'store')->name('store');
                    Route::patch('{slug}', 'update')->name('update');
                    Route::delete('{slug}', 'destroy')->name('destroy');
                });
            });
        }

        if ($enabled('transfer')) {
            Route::controller(TransferController::class)->name('transfer.')->middleware(IdempotentWrites::class)->group(static function (): void {
                Route::post('export', 'export')->name('export');
                Route::post('import', 'import')->name('import');
                Route::post('diff', 'diff')->name('diff');
            });
        }
    });
});

if ($enabled('audit')) {
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
}

if ($enabled('configuration')) {
    Route::controller(ConfigurationController::class)->prefix('configuration')->name('configuration.')->group(static function (): void {
        Route::get('/', 'index')->name('index');

        Route::middleware(IdempotentWrites::class)->group(static function (): void {
            Route::put('{kind}/{name}', 'update')->name('update');
            Route::delete('{kind}/{name}', 'destroy')->name('destroy');
        });
    });
}
