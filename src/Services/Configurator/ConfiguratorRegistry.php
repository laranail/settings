<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Configurator;

use Closure;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Package\Tools\Support\Resilience\FailurePolicy;
use Simtabi\Laranail\Settings\Configurator\AppliedSetup;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;
use Simtabi\Laranail\Settings\Configurator\ConfiguredBehaviour;
use Simtabi\Laranail\Settings\Configurator\Contribution;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Configurator\SetupValues;
use Simtabi\Laranail\Settings\Contracts\ModuleSettingsContributor;
use Simtabi\Laranail\Settings\Contracts\PackageSettingsContributor;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Enums\ContributionStatus;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Throwable;

/**
 * Every registered piece of the settings setup, what it came to, and whether it is sealed: the
 * application's one configurator plus package and module contributors. One instance per
 * application, bound on the container (never in a static and never request-scoped), so it lives as
 * long as the application and a second application in the same process gets its own.
 *
 * Registering only records the class and the call site. While the application boots, after every
 * provider has registered and before any of them boots, each registration runs into its own
 * values; then the application's choices decide what applies (mode and exclusions), packages
 * first, then modules, then the application. A name has one owner: a second declaration of it from
 * anyone else throws, naming both. The setup seals once the application has booted.
 */
final class ConfiguratorRegistry
{
    /** @var list<ConfiguratorRegistration> in registration order */
    private array $registrations = [];

    private ?AppliedSetup $applied = null;

    private ConfiguredBehaviour $behaviour;

    /** @var list<SetupValues> */
    private array $runs = [];

    private bool $ran = false;

    public function __construct()
    {
        $this->behaviour = new ConfiguredBehaviour;
    }

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
     * Records a configurator or contributor.
     *
     * @param  ConfiguratorScope|null  $expected  the only scope the call accepts, or null for any
     * @param  Closure(Throwable): void  $report  sends a refused registration to the exception handler
     */
    public function register(string $class, string $file, int $line, Closure $report, ?ConfiguratorScope $expected = null): ConfiguratorRegistration
    {
        try {
            $registration = ConfiguratorRegistration::of($class, $file, $line, $expected);

            foreach ($this->registrations as $existing) {
                if ($registration->scope === ConfiguratorScope::Application && $existing->scope === ConfiguratorScope::Application) {
                    throw new SettingsMisuse(sprintf(
                        'One settings configurator per application: %s is already registered, so %s was refused. Compose both in one configurator\'s configure().',
                        $existing->describe(),
                        $registration->describe(),
                    ));
                }

                if ($existing->class === $registration->class || ($existing->scope === $registration->scope && $existing->identity === $registration->identity)) {
                    throw new SettingsMisuse(sprintf('%s is already registered as %s, so %s was refused.', $registration->identity, $existing->describe(), $registration->describe()));
                }
            }

            if ($this->ran) {
                throw new SettingsMisuse(sprintf(
                    'The settings %s was registered after the application started booting (from a boot() method or a deferred provider), so it would reach some services and not others. Register it from a service provider\'s register().',
                    $registration->describe(),
                ));
            }
        } catch (SettingsMisuse $exception) {
            $report($exception);

            throw $exception;
        }

        return $this->registrations[] = $registration;
    }

    /** The application's configurator registration, or null without one. */
    public function registration(): ?ConfiguratorRegistration
    {
        foreach ($this->registrations as $registration) {
            if ($registration->scope === ConfiguratorScope::Application) {
                return $registration;
            }
        }

        return null;
    }

    /** @return list<ConfiguratorRegistration> every registration, in registration order */
    public function registrations(): array
    {
        return $this->registrations;
    }

    /** The behaviour the setup asked for (empty until it has run, or with nothing registered). */
    public function behaviour(): ConfiguredBehaviour
    {
        return $this->behaviour;
    }

    /** What the setup came to, once it has run (null when nothing was registered). */
    public function applied(): ?AppliedSetup
    {
        return $this->applied;
    }

    /**
     * Runs every registration once, decides what applies, and writes it into config, unless the
     * configuration is cached: the cached values were captured from these same registrations.
     *
     * @param  Closure(string, mixed): void  $write  sets one `laranail.settings` path
     * @param  Closure(Throwable): void  $report  sends a refused late change to the exception handler
     */
    public function apply(Container $app, bool $configCached, Closure $write, Closure $report): ?AppliedSetup
    {
        if ($this->ran) {
            return $this->applied;
        }

        $this->ran = true;

        if ($this->registrations === []) {
            return null;
        }

        $ordered = [
            ...$this->inScope(ConfiguratorScope::Package),
            ...$this->inScope(ConfiguratorScope::Module),
            ...$this->inScope(ConfiguratorScope::Application),
        ];

        $application = null;

        foreach ($ordered as $registration) {
            $this->runs[] = $run = $this->run($app, $registration, $report);

            if ($registration->scope === ConfiguratorScope::Application) {
                $application = $run;
            }
        }

        $mode = $application?->mode() ?? ConfiguratorMode::Loose;
        [$values, $owners, $contributions, $behaviour, $lists] = FailurePolicy::run(fn (): array => $this->merge($mode, $application), 'laranail/settings setup') ?? [[], [], [], [], []];
        $this->behaviour = ConfiguredBehaviour::from($behaviour, $lists);

        foreach ($contributions as $contribution) {
            if ($contribution->status === ContributionStatus::Refused) {
                FailurePolicy::warn('laranail/settings contributor refused in strict mode', ['contributor' => $contribution->registration->describe(), 'paths' => array_keys($contribution->values)]);
            }
        }

        if (! $configCached) {
            foreach ($values as $path => $value) {
                $write($path, $value);
            }
        }

        return $this->applied = new AppliedSetup($application?->registration(), $values, ! $configCached, $mode, $owners, $contributions);
    }

    /** Refuses every further change to the setup: the application has booted. */
    public function seal(): void
    {
        $this->ran = true;

        foreach ($this->runs as $run) {
            $run->seal();
        }
    }

    /** @return list<ConfiguratorRegistration> */
    private function inScope(ConfiguratorScope $scope): array
    {
        return array_values(array_filter($this->registrations, static fn (ConfiguratorRegistration $registration): bool => $registration->scope === $scope));
    }

    /** @param Closure(Throwable): void $report */
    private function run(Container $app, ConfiguratorRegistration $registration, Closure $report): SetupValues
    {
        $values = new SetupValues($registration, $report);

        FailurePolicy::run(static function () use ($app, $registration, $values): void {
            $instance = $app->make($registration->class);

            if (! $instance instanceof SettingsConfigurator && ! $instance instanceof PackageSettingsContributor && ! $instance instanceof ModuleSettingsContributor) {
                throw new SettingsMisuse(sprintf('The container built [%s] for %s, which is not a settings configurator or contributor.', get_debug_type($instance), $registration->describe()));
            }

            $instance->configure(new SettingsSetup($values));
        }, 'laranail/settings '.$registration->describe());

        return $values;
    }

    /**
     * Applies the runs in order under the mode and the application's exclusions. Every path has
     * one owner: a store or layer by its name, anything else by its path.
     *
     * @return array{0: array<string, mixed>, 1: array<string, ConfiguratorRegistration>, 2: list<Contribution>, 3: array<string, mixed>, 4: array<string, list<mixed>>}
     */
    private function merge(ConfiguratorMode $mode, ?SetupValues $application): array
    {
        $values = [];
        $owners = [];
        $claims = [];
        $contributions = [];
        $behaviour = [];
        $lists = [];

        foreach ($this->runs as $run) {
            $registration = $run->registration();
            $excludedAt = $application?->excludedAt($registration);
            $status = match (true) {
                $registration->scope === ConfiguratorScope::Application => ContributionStatus::Applied,
                $excludedAt !== null => ContributionStatus::Excluded,
                $mode === ConfiguratorMode::Strict => ContributionStatus::Refused,
                default => ContributionStatus::Applied,
            };
            $contributions[] = new Contribution($registration, $status, $run->all(), $excludedAt);

            if ($status !== ContributionStatus::Applied) {
                continue;
            }

            foreach ([[$run->all(), false], [$run->behaviour(), true]] as [$entries, $isBehaviour]) {
                foreach ($entries as $path => $value) {
                    $claim = $this->claim($path);
                    $owner = $claims[$claim] ?? null;

                    if ($owner !== null && $owner !== $registration) {
                        throw new InvalidConfiguration(sprintf(
                            'laranail.settings.%s is owned by the %s, so the %s may not set it. Each name has one owner; the application can leave a package or module out with exclude().',
                            $claim,
                            $owner->describe(),
                            $registration->describe(),
                        ));
                    }

                    $claims[$claim] = $registration;
                    $owners[$path] = $registration;

                    if ($isBehaviour) {
                        $behaviour[$path] = $value;
                    } else {
                        $values[$path] = $value;
                    }
                }
            }

            foreach ($run->lists() as $list => $listed) {
                $lists[$list] = [...$lists[$list] ?? [], ...$listed];
            }
        }

        return [$values, $owners, $contributions, $behaviour, $lists];
    }

    /** The name a path belongs to: `stores.<name>` and `layers.<name>` as a whole, any other path by itself. */
    private function claim(string $path): string
    {
        $segments = explode('.', $path);

        return in_array($segments[0], ['stores', 'layers'], true) && isset($segments[1]) ? $segments[0].'.'.$segments[1] : $path;
    }
}
