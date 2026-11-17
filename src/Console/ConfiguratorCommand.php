<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Data\ConfigurationValue;
use Simtabi\Laranail\Settings\Configurator\Contribution;
use Simtabi\Laranail\Settings\Actions\DescribeConfiguration;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Show who set the package up and where each configuration key's value came from: the
 * application's configurator, every package and module contributor with what became of it, and
 * each key with its value (secrets redacted) and source.
 */
final class ConfiguratorCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.configurator
        {--key= : Only keys starting with this dot path (tables.prefix, stores.teams)}
        {--owner= : Only keys this identity owns (app, a package name or a module name)}
        {--scope= : Only registrations of this scope: package, module or application}
        {--json : Emit the report as JSON}';

    protected $description = 'Show the settings configurator and contributors, and where each configuration value comes from';

    public function handle(DescribeConfiguration $describe): int
    {
        $report = $describe->handle();
        $key = $this->string('key');
        $owner = $this->string('owner');
        $scope = $this->string('scope');

        $registrations = array_values(array_filter($report->registrations, static fn (ConfiguratorRegistration $registration): bool => $scope === null || $registration->scope->value === $scope));
        $entries = array_values(array_filter($report->entries, static fn (ConfigurationValue $entry): bool => ($key === null || $entry->key === $key || str_starts_with($entry->key, $key . '.'))
            && ($owner === null || $entry->owner === $owner)));
        $statuses = [];

        foreach ($report->contributions as $contribution) {
            $statuses[$contribution->registration->class] = $contribution;
        }

        if ($this->option('json') === true) {
            $this->line(Json::encode([
                'mode'          => $report->mode->value,
                'config_cached' => $report->configCached,
                'registrations' => array_map(static fn (ConfiguratorRegistration $registration): array => [
                    'scope'       => $registration->scope->value,
                    'identity'    => $registration->identity,
                    'class'       => $registration->class,
                    'class_file'  => $registration->classFile,
                    'site'        => $registration->site(),
                    'status'      => ($statuses[$registration->class] ?? null)?->status->value,
                    'excluded_at' => ($statuses[$registration->class] ?? null)?->excludedAt,
                ], $registrations),
                'entries'  => array_map(static fn (ConfigurationValue $entry): array => $entry->toArray(), $entries),
                'leftover' => $report->leftover,
            ], 'the configurator report'));

            return self::SUCCESS;
        }

        $this->line(sprintf('Mode: %s. Config cached: %s.', $report->mode->value, $report->configCached ? 'yes' : 'no'));
        $this->line($report->application instanceof ConfiguratorRegistration
            ? sprintf('Configurator: %s, in %s, registered at %s.', $report->application->class, $report->application->classFile, $report->application->site())
            : 'No application configurator is registered.');

        if ($registrations !== []) {
            $this->table(['Scope', 'Identity', 'Class', 'Registered at', 'Status'], array_map(static fn (ConfiguratorRegistration $registration): array => [
                $registration->scope->value,
                $registration->identity,
                $registration->class,
                $registration->site(),
                self::status($statuses[$registration->class] ?? null),
            ], $registrations));
        }

        $this->table(['Key', 'Value', 'Source'], array_map(static fn (ConfigurationValue $entry): array => [
            $entry->key,
            self::show($entry->value),
            $entry->owner === null ? $entry->source : $entry->source . ' (' . $entry->owner . ')',
        ], $entries));

        foreach ($report->leftover as $variable => $method) {
            $this->warn(sprintf('%s is still set in the environment; the package no longer needs it. Use the configurator\'s %s instead.', $variable, $method));
        }

        return self::SUCCESS;
    }

    private static function status(?Contribution $contribution): string
    {
        return match (true) {
            ! $contribution instanceof Contribution => 'not run yet',
            $contribution->excludedAt !== null      => 'excluded by app (' . $contribution->excludedAt . ')',
            default                                 => $contribution->status->label(),
        };
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null   => 'null',
            is_bool($value)   => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value)  => Json::encode($value, 'a configuration value'),
            default           => get_debug_type($value),
        };
    }

    private function string(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
