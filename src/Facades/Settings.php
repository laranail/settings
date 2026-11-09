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
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Testing\FakeLog;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Drivers\ArrayDriver;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Testing\SettingsFake;
use Simtabi\Laranail\Settings\Support\ValuePipeline;
use Simtabi\Laranail\Settings\Actions\SettingActions;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Contracts\Settings as SettingsContract;

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
        $writer = $app->make(SettingWriter::class);

        $fake = new SettingsFake(new SettingsManager(
            $stores,
            $app->make(DefinitionRegistry::class),
            new Resolver($stores, $app->make(ValuePipeline::class), self::fallbackLocale($app->make('config')->get('app.fallback_locale'))),
            $writer,
            actions: SettingActions::using($writer, $access),
            access: $access,
            runtime: $app->make(RuntimeConfiguration::class),
        ), $log);

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
