<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Facades;

use LogicException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Testing\FakeLog;
use Simtabi\Laranail\Settings\Audit\AuditQuery;
use Simtabi\Laranail\Settings\Data\HistoryDiff;
use Simtabi\Laranail\Settings\Enums\ExportMode;
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
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Categories\CategoryManager;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Services\Resolution\Resolver;
use Simtabi\Laranail\Settings\Actions\Reads\ListDefinitions;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange;
use Simtabi\Laranail\Settings\Contracts\Settings as SettingsContract;
use Simtabi\Laranail\Settings\Configuration\PersistedConfigurationEditor;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * An application convenience over the {@see SettingsContract} binding. The package's own
 * classes never use it; they take the contract through their constructors.
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
     * Replace the binding with the real engine over in-memory layers, recording writes and audit
     * entries. Install it before resolving the code under test.
     */
    public static function fake(): SettingsFake
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new LogicException('Settings::fake() needs a booted application.');
        }

        $stores = $app->make(StoreRegistry::class)->withEveryLayer(new ArrayDriver);
        $log = new FakeLog(new InMemoryAuditSink);
        $access = $app->make(AccessGuard::class);
        $access = $access->withAuditor($access->auditor()->alsoTo($log->audit));
        $guard = new WriteGuard($access, $app->make(SettingWriter::class), $app->make(ProposeChange::class));

        // The real engine, with the in-memory stores, the recording access guard and the writes
        // that go through it; every other collaborator is the container's.
        $fake = new SettingsFake($app->make(SettingsManager::class, [
            'stores'          => $stores,
            'resolver'        => new Resolver($stores, $app->make(ValuePipeline::class), self::fallbackLocale($app->make('config')->get('app.fallback_locale'))),
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

    protected static function getFacadeAccessor(): string
    {
        return SettingsContract::class;
    }

    private static function fallbackLocale(mixed $configured): string
    {
        return is_string($configured) ? $configured : 'en';
    }
}
