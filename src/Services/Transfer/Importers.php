<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Transfer;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Settings\Contracts\Importer;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The configured importers `import --from=<name>` can use, built on first use.
 */
final readonly class Importers
{
    /**
     * @param array<array-key, mixed> $classes `laranail.settings.importers`
     */
    public function __construct(
        private Container $container,
        #[Config('laranail.settings.importers', [])]
        private array $classes = [],
    ) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_map(strval(...), array_keys($this->classes)));
    }

    public function get(string $name): Importer
    {
        $class = $this->classes[$name] ?? null;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Importer::class)) {
            throw new InvalidConfiguration(sprintf('laranail.settings.importers.%s: [%s] is not a class implementing %s (known importers: %s).', $name, is_string($class) ? $class : 'missing', Importer::class, implode(', ', $this->names())));
        }

        $importer = $this->container->make($class);

        return $importer instanceof Importer ? $importer : throw new InvalidConfiguration(sprintf('laranail.settings.importers.%s: [%s] did not build an importer.', $name, $class));
    }
}
