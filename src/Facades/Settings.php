<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Facades;

use Closure;
use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Testing\FakeLog;
use Simtabi\Laranail\Settings\Audit\AuditQuery;
use Simtabi\Laranail\Settings\Data\HistoryDiff;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Simtabi\Laranail\Settings\Actions\QueryAudit;
use Simtabi\Laranail\Settings\Actions\SetSetting;
use Simtabi\Laranail\Settings\Actions\WriteGuard;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Actions\LockSetting;
use Simtabi\Laranail\Settings\Approvals\Approvals;
use Simtabi\Laranail\Settings\Drivers\ArrayDriver;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\History\HistoryQuery;
use Simtabi\Laranail\Settings\Testing\SettingsFake;
use Simtabi\Laranail\Settings\Actions\ForgetSetting;
use Simtabi\Laranail\Settings\Actions\UnlockSetting;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Categories\CategoryManager;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Services\Resolution\Resolver;
use Simtabi\Laranail\Settings\Actions\Reads\ListDefinitions;
use Simtabi\Laranail\Settings\Services\Resolution\Overrides;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;
use Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange;
use Simtabi\Laranail\Settings\Configuration\PromotionSettings;
use Simtabi\Laranail\Settings\Configurator\ConfiguredBehaviour;
use Simtabi\Laranail\Settings\Contracts\ModuleSettingsContributor;
use Simtabi\Laranail\Settings\Contracts\PackageSettingsContributor;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;
use Simtabi\Laranail\Settings\Contracts\Settings as SettingsContract;
use Simtabi\Laranail\Settings\Configuration\PersistedConfigurationEditor;
use Simtabi\Laranail\Settings\Services\Configurator\ConfiguratorRegistry;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * Static access to the {@see SettingsContract} binding.
 *
 * @method static SettingsContract store(string $name)
 * @method static SettingsContract for(Settable|OwnerRef $owner)
 * @method static SettingsContract locale(string $locale)
 * @method static SettingsContract as(Authenticatable|Actor $actor)
 * @method static SettingsContract asSystem(?string $reason = null)
 * @method static SettingsContract asCurrentActor(?Request $request = null)
 * @method static SettingsContract because(string $reason)
 * @method static Actor|null actor()
 * @method static RuntimeConfiguration configuration()
 * @method static mixed withConfiguration(callable $change, callable $run)
 * @method static void override(string|array<string, mixed> $key, mixed $value = null)
 * @method static void forgetOverride(string $key)
 * @method static bool hasOverride(string $key)
 * @method static void clearOverrides()
 * @method static mixed withOverrides(array<string, mixed> $values, callable $run)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static Lookup lookup(string $key)
 * @method static bool has(string $key)
 * @method static string string(string $key)
 * @method static int integer(string $key)
 * @method static float float(string $key)
 * @method static bool boolean(string $key)
 * @method static array<array-key, mixed> array(string $key)
 * @method static Explanation explain(string $key)
 * @method static void set(string $key, mixed $value)
 * @method static void forget(string $key)
 * @method static void lock(string $key)
 * @method static void unlock(string $key)
 * @method static void flush()
 * @method static void preload(iterable<Settable|OwnerRef> $owners)
 * @method static CategoryManager categories()
 * @method static PromotionReport promote(?PromotionRequest $request = null)
 * @method static PromotionReport|null promoteWhenReady(?PromotionSelection $only = null)
 * @method static PromotionSettings promotion()
 * @method static AuditQuery audit()
 * @method static bool missing(string $key)
 * @method static array<string, mixed> many(array<array-key, mixed> $keys)
 * @method static array<string, mixed> all(?string $prefix = null)
 * @method static list<Definition> definitions()
 * @method static void setMany(array<string, mixed> $values)
 * @method static void forgetMany(iterable<string> $keys)
 * @method static mixed remember(string $key, callable $value)
 * @method static void clearCache()
 * @method static int warmCache()
 * @method static HistoryQuery history()
 * @method static int rollback(string $key, int $toVersion)
 * @method static HistoryDiff historyDiff(string $key, int $from, int $to)
 * @method static ChangeRequest propose(string $key, mixed $value)
 * @method static Approvals approvals()
 * @method static ExportDocument export(ExportMode $mode = ExportMode::Redacted)
 * @method static ImportReport import(ExportDocument $document)
 * @method static ImportReport previewImport(ExportDocument $document)
 * @method static StoreDiff compare(ExportDocument $document)
 * @method static PersistedConfigurationEditor persistedConfiguration()
 *
 * @see SettingsContract
 */
final class Settings extends Facade
{
    /**
     * Swap the binding for the real engine over in-memory layers, recording writes and audit
     * entries. Call it before resolving the code under test.
     */
    public static function fake(): SettingsFake
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new SettingsMisuse('Settings::fake() needs a booted application.');
        }

        $stores = $app->make(StoreRegistry::class)->withEveryLayer(new ArrayDriver);
        $log = new FakeLog(new InMemoryAuditSink);
        $access = $app->make(AccessGuard::class);
        $access = $access->withAuditor($access->auditor()->alsoTo($log->audit));
        $guard = new WriteGuard($access, $app->make(SettingWriter::class), $app->make(ProposeChange::class));

        // The engine and its resolver share the request's overrides, so they end with the request.
        $overrides = $app->make(Overrides::class);
        $fake = new SettingsFake($app->make(SettingsManager::class, [
            'stores'          => $stores,
            'overrides'       => $overrides,
            'resolver'        => new Resolver($stores, $app->make(ValuePipeline::class), $overrides, self::fallbackLocale($app->make('config')->get('app.fallback_locale')), behaviour: $app->make(ConfiguredBehaviour::class)),
            'access'          => $access,
            'set'             => $app->make(SetSetting::class, ['guard' => $guard]),
            'forget'          => $app->make(ForgetSetting::class, ['guard' => $guard]),
            'lock'            => $app->make(LockSetting::class, ['guard' => $guard]),
            'unlock'          => $app->make(UnlockSetting::class, ['guard' => $guard]),
            'auditQuery'      => new QueryAudit($stores, $access),
            'listDefinitions' => $app->make(ListDefinitions::class, ['access' => $access]),
        ]), $log);

        self::swap($fake);
        $app->instance(SettingsContract::class, $fake);

        return $fake;
    }

    /**
     * Register the application's configurator, once, from a service provider's `register()`.
     * It runs while the application boots, so it may depend on any binding. A second
     * registration, or one made after booting started, throws naming both call sites.
     *
     * @param class-string<SettingsConfigurator> $configurator
     */
    public static function useConfigurator(string $configurator): ConfiguratorRegistration
    {
        $app = self::application('Settings::useConfigurator()');
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        return ConfiguratorRegistry::of($app)->register($configurator, $caller['file'] ?? 'unknown', $caller['line'] ?? 0, self::reporter($app), ConfiguratorScope::Application);
    }

    /**
     * Register a configurator or contributor from a service provider's `register()`: the
     * application's configurator, a package's contributor or a module's, told apart by the
     * contract the class implements. Each is recorded with its call site and identity; a second
     * registration of one class or one identity throws, naming both.
     *
     * @param class-string<SettingsConfigurator|PackageSettingsContributor|ModuleSettingsContributor> $class
     */
    public static function register(string $class): ConfiguratorRegistration
    {
        $app = self::application('Settings::register()');
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        return ConfiguratorRegistry::of($app)->register($class, $caller['file'] ?? 'unknown', $caller['line'] ?? 0, self::reporter($app));
    }

    /** Where the application's configurator was registered, or null without one. */
    public static function configuratorRegistration(): ?ConfiguratorRegistration
    {
        return ConfiguratorRegistry::of(self::application('Settings::configuratorRegistration()'))->registration();
    }

    /**
     * Sends a refused registration or a late change to the application's exception handler,
     * resolved when it happens, so a handler swapped in later (a test's fake) receives it.
     *
     * @return Closure(Throwable): void
     */
    public static function reporter(Container $app): Closure
    {
        return static function (Throwable $exception) use ($app): void {
            if ($app->bound(ExceptionHandler::class)) {
                $app->make(ExceptionHandler::class)->report($exception);
            }
        };
    }

    protected static function getFacadeAccessor(): string
    {
        return SettingsContract::class;
    }

    private static function application(string $method): Container
    {
        return self::getFacadeApplication() ?? throw new SettingsMisuse($method . ' needs an application: call it from a service provider.');
    }

    private static function fallbackLocale(mixed $configured): string
    {
        return is_string($configured) ? $configured : 'en';
    }
}
