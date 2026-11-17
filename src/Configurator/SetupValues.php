<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Closure;
use Throwable;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The values one configurator or contributor run sets, by dot path under `laranail.settings`,
 * shared by the setup and its sub-setups, with the application's own decisions about contributors
 * (the mode and its exclusions). Validates each value where it is set, naming the setup method and
 * the path, refuses application-wide decisions from a package or module, and refuses every change
 * once the application has booted (the setup is sealed).
 */
final class SetupValues
{
    /** @var array<string, mixed> */
    private array $values = [];

    private bool $sealed = false;

    private ?ConfiguratorMode $mode = null;

    /** @var array<string, string> excluded `package:<name>` or `module:<name>` => the exclude() call site */
    private array $excluded = [];

    /** @param Closure(Throwable): void $report sends a refused late change to the exception handler */
    public function __construct(
        private readonly ConfiguratorRegistration $registration,
        private readonly Closure $report,
    ) {}

    public static function invalid(string $method, string $path, string $expected, mixed $value): InvalidConfiguration
    {
        return new InvalidConfiguration(sprintf('%s (laranail.settings.%s): expected %s, got [%s].', $method, $path, $expected, is_scalar($value) ? (string) $value : get_debug_type($value)));
    }

    public static function name(string $method, string $path, string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw self::invalid($method, $path, 'a name matching [a-z][a-z0-9_]*, at most 64 characters', $name);
        }

        return $name;
    }

    public static function atLeast(string $method, string $path, int $value, int $minimum): int
    {
        if ($value < $minimum) {
            throw self::invalid($method, $path, 'an integer of at least ' . $minimum, $value);
        }

        return $value;
    }

    public static function filled(string $method, string $path, string $value): string
    {
        if (trim($value) === '') {
            throw self::invalid($method, $path, 'a non-empty string', $value);
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public static function oneOf(string $method, string $path, string $value, array $allowed): string
    {
        if (! in_array($value, $allowed, true)) {
            throw self::invalid($method, $path, 'one of ' . implode(', ', $allowed), $value);
        }

        return $value;
    }

    public function set(string $path, mixed $value): void
    {
        $this->guard($path);
        $this->values[$path] = $value;
    }

    public function registration(): ConfiguratorRegistration
    {
        return $this->registration;
    }

    /** Refuses an application-wide decision from a package or module. */
    public function applicationOnly(string $method): void
    {
        if ($this->registration->scope !== ConfiguratorScope::Application) {
            throw new InvalidConfiguration(sprintf('%s is the application\'s decision; the %s refused it. Set it in the application\'s configurator.', $method, $this->registration->describe()));
        }
    }

    public function setMode(ConfiguratorMode $mode): void
    {
        $this->applicationOnly('mode()');
        $this->guard('mode');
        $this->mode = $mode;
    }

    public function mode(): ?ConfiguratorMode
    {
        return $this->mode;
    }

    public function exclude(ConfiguratorScope $scope, string $identity, string $site): void
    {
        $this->applicationOnly('exclude()');
        $this->guard('exclude');
        $this->excluded[$scope->value . ':' . self::filled('exclude()', 'exclude', $identity)] = $site;
    }

    /** The exclude() call site when the application excluded this registration, else null. */
    public function excludedAt(ConfiguratorRegistration $registration): ?string
    {
        return $this->excluded[$registration->scope->value . ':' . $registration->identity] ?? null;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function sealed(): bool
    {
        return $this->sealed;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    private function guard(string $path): void
    {
        if (! $this->sealed) {
            return;
        }

        $exception = new SettingsMisuse(sprintf(
            'The settings setup of %s is sealed: the application has booted, so a change to [%s] would reach some services and not others. Use Settings::configuration() to change configuration for one request.',
            $this->registration->describe(),
            $path,
        ));
        ($this->report)($exception);

        throw $exception;
    }
}
