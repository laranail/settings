<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Closure;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Enums\Stage;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorCheck;

/**
 * The behaviour the setup asked for, which cannot live in config: drivers, casters, definitions,
 * the authorizer, verifier and actor resolver, extra audit sinks and the log destination. The
 * provider builds each service from it ahead of config, and a request's `configuration()` still
 * wins over it. A class name is built through the container when the service is.
 */
final readonly class ConfiguredBehaviour
{
    /**
     * @param array<string, Closure(LayerConfig): Driver> $drivers
     * @param array<string, Caster> $casters
     * @param array<string, Closure(DefinitionBuilder): mixed> $definitions by key
     * @param Authorizer|Closure|class-string<Authorizer>|null $authorizer
     * @param Verifier|Closure|class-string<Verifier>|null $verifier
     * @param ActorResolver|Closure|class-string<ActorResolver>|null $actorResolver
     * @param list<AuditSink|class-string<AuditSink>> $auditSinks added to the configured sinks
     * @param LoggerInterface|false|null $logger false: nowhere
     * @param list<Closure(Settings): Settings> $decorators innermost first
     * @param array<string, list<Closure(mixed, Key): mixed>> $stages by Stage value, in order
     * @param list<array{0: class-string, 1: Closure|class-string}> $listeners event => listener, in order
     * @param array<string, Closure> $macros by name
     * @param list<DoctorCheck> $doctorChecks
     */
    public function __construct(
        public array $drivers = [],
        public array $casters = [],
        public array $definitions = [],
        public Authorizer|Closure|string|null $authorizer = null,
        public Verifier|Closure|string|null $verifier = null,
        public ActorResolver|Closure|string|null $actorResolver = null,
        public array $auditSinks = [],
        public LoggerInterface|false|null $logger = null,
        public array $decorators = [],
        public array $stages = [],
        public array $listeners = [],
        public array $macros = [],
        public array $doctorChecks = [],
    ) {}

    /**
     * @param array<string, mixed> $entries claimed entries by path (`drivers.<name>`, `casters.<alias>`, `definitions.<key>`, `authorization.driver`, …)
     * @param array<string, list<mixed>> $lists additive entries by list (`audit.sinks`)
     */
    public static function from(array $entries, array $lists): self
    {
        $named = static function (string $prefix) use ($entries): array {
            $found = [];

            foreach ($entries as $path => $value) {
                if (str_starts_with($path, $prefix . '.')) {
                    $found[substr($path, strlen($prefix) + 1)] = $value;
                }
            }

            return $found;
        };

        /** @var array<string, Closure(LayerConfig): Driver> $drivers */
        $drivers = $named('drivers');
        /** @var array<string, Caster> $casters */
        $casters = $named('casters');
        /** @var array<string, Closure(DefinitionBuilder): mixed> $definitions */
        $definitions = $named('definitions');
        /** @var Authorizer|Closure|class-string<Authorizer>|null $authorizer */
        $authorizer = $entries['authorization.driver'] ?? null;
        /** @var Verifier|Closure|class-string<Verifier>|null $verifier */
        $verifier = $entries['verification.driver'] ?? null;
        /** @var ActorResolver|Closure|class-string<ActorResolver>|null $resolver */
        $resolver = $entries['authentication.resolver'] ?? null;
        /** @var list<AuditSink|class-string<AuditSink>> $sinks */
        $sinks = $lists['audit.sinks'] ?? [];
        /** @var LoggerInterface|false|null $logger */
        $logger = $entries['logging.channel'] ?? null;

        /** @var list<Closure(Settings): Settings> $decorators */
        $decorators = $lists['decorators'] ?? [];
        /** @var list<Closure(mixed, Key): mixed> $before */
        $before = $lists['pipes.' . Stage::BeforeWrite->value] ?? [];
        /** @var list<Closure(mixed, Key): mixed> $after */
        $after = $lists['pipes.' . Stage::AfterRead->value] ?? [];
        /** @var list<array{0: class-string, 1: Closure|class-string}> $listeners */
        $listeners = $lists['listeners'] ?? [];
        /** @var array<string, Closure> $macros */
        $macros = $named('macros');
        /** @var list<DoctorCheck> $checks */
        $checks = $lists['doctor.checks'] ?? [];

        return new self($drivers, $casters, $definitions, $authorizer, $verifier, $resolver, $sinks, $logger, $decorators, [Stage::BeforeWrite->value => $before, Stage::AfterRead->value => $after], $listeners, $macros, $checks);
    }

    /** The value after each stage at this position, in the order they were added. */
    public function staged(Stage $stage, Key $key, mixed $value): mixed
    {
        foreach ($this->stages[$stage->value] ?? [] as $pipe) {
            $value = $pipe($value, $key);
        }

        return $value;
    }
}
