<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Facades;

use LogicException;
use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Drivers\ArrayDriver;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Testing\SettingsFake;
use Simtabi\Laranail\Settings\Support\ValuePipeline;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Contracts\Settings as SettingsContract;

/**
 * An application convenience over the {@see SettingsContract} binding. The package's own
 * classes never use it; they take the contract through their constructors.
 *
 * @method static SettingsContract store(string $name)
 * @method static SettingsContract for(Settable|OwnerRef $owner)
 * @method static SettingsContract locale(string $locale)
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
     * Replace the binding with the real engine over in-memory layers, recording writes.
     * Install it before resolving the code under test.
     */
    public static function fake(): SettingsFake
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new LogicException('Settings::fake() needs a booted application.');
        }

        $stores = $app->make(StoreRegistry::class)->withEveryLayer(new ArrayDriver);

        $fake = new SettingsFake(new SettingsManager(
            $stores,
            $app->make(DefinitionRegistry::class),
            new Resolver($stores, $app->make(ValuePipeline::class), self::fallbackLocale($app->make('config')->get('app.fallback_locale'))),
            $app->make(SettingWriter::class),
        ));

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
