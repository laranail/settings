<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Configurator;

use Closure;
use Throwable;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Settings\Configurator\SetupValues;
use Simtabi\Laranail\Settings\Configurator\AppliedSetup;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;
use Simtabi\Laranail\Package\Tools\Support\Resilience\FailurePolicy;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;

/**
 * The application's one settings configurator: where it was registered, what it set, and whether
 * it is sealed. One instance per application, bound on the container (never in a static and never
 * request-scoped), so it lives as long as the application and a second application in the same
 * process gets its own.
 *
 * Registering only records the class and the call site. The package runs the configurator while
 * the application boots, after every provider has registered and before any of them boots, so the
 * configurator can depend on any binding; the setup seals once the application has booted.
 */
final class ConfiguratorRegistry
{
    private ?ConfiguratorRegistration $registration = null;

    private ?AppliedSetup $applied = null;

    private ?SetupValues $values = null;

    private bool $ran = false;

    /** The registry of this application, bound on first use (a call may come before the provider registers). */
    public static function of(Container $app): self
    {
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self);
        }

        $registry = $app->make(self::class);

        return $registry instanceof self ? $registry : throw new SettingsMisuse(sprintf('%s is bound to [%s].', self::class, get_debug_type($registry)));
    }

    /**
     * Records the application's configurator.
     *
     * @param Closure(Throwable): void $report sends a refused registration to the exception handler
     */
    public function register(string $class, string $file, int $line, Closure $report): ConfiguratorRegistration
    {
        try {
            $registration = ConfiguratorRegistration::of($class, $file, $line);

            if ($this->registration instanceof ConfiguratorRegistration) {
                throw new SettingsMisuse(sprintf(
                    'One settings configurator per application: %s is already registered, so %s was refused. Compose both in one configurator\'s configure().',
                    $this->registration->describe(),
                    $registration->describe(),
                ));
            }

            if ($this->ran) {
                throw new SettingsMisuse(sprintf(
                    'The settings configurator %s was registered after the application started booting (from a boot() method or a deferred provider), so it would reach some services and not others. Register it from a service provider\'s register().',
                    $registration->describe(),
                ));
            }
        } catch (SettingsMisuse $exception) {
            $report($exception);

            throw $exception;
        }

        return $this->registration = $registration;
    }

    public function registration(): ?ConfiguratorRegistration
    {
        return $this->registration;
    }

    /** What the configurator set, once it has run (null without one). */
    public function applied(): ?AppliedSetup
    {
        return $this->applied;
    }

    /**
     * Runs the registered configurator once and writes what it set into config, unless the
     * configuration is cached: the cached values were captured from this configurator.
     *
     * @param Closure(string, mixed): void $write sets one `laranail.settings` path
     * @param Closure(Throwable): void $report sends a refused late change to the exception handler
     */
    public function apply(Container $app, bool $configCached, Closure $write, Closure $report): ?AppliedSetup
    {
        if ($this->ran) {
            return $this->applied;
        }

        $this->ran = true;
        $registration = $this->registration;

        if (! $registration instanceof ConfiguratorRegistration) {
            return null;
        }

        $values = $this->values = new SetupValues($registration, $report);

        FailurePolicy::run(static function () use ($app, $registration, $values): void {
            $configurator = $app->make($registration->class);

            if (! $configurator instanceof SettingsConfigurator) {
                throw new SettingsMisuse(sprintf('The container built [%s] for %s, which is not a %s.', get_debug_type($configurator), $registration->describe(), SettingsConfigurator::class));
            }

            $configurator->configure(new SettingsSetup($values));
        }, 'laranail/settings configurator ' . $registration->describe());

        if (! $configCached) {
            foreach ($values->all() as $path => $value) {
                $write($path, $value);
            }
        }

        return $this->applied = new AppliedSetup($registration, $values->all(), ! $configCached);
    }

    /** Refuses every further change to the setup: the application has booted. */
    public function seal(): void
    {
        $this->ran = true;
        $this->values?->seal();
    }
}
