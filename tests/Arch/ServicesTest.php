<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Contracts\Importer;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\ReadOnlyValues;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Contracts\OwnerResolver;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\Settings\Contracts\ValueEncrypter;
use Simtabi\Laranail\Settings\Contracts\ValueValidator;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Contracts\BootstrapEvidence;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;

/*
 * The Services namespace (Phase 4 plan §1): stateful collaborators that own I/O or a body of
 * rules live under Services\, and nowhere else. Contract implementations (drivers, sinks,
 * authorizers, casters, verifiers, resolvers, discriminators) stay beside their kind, so nothing
 * under Services\ implements a plug-in contract; and a repository or registry anywhere else is a
 * service that was filed in the wrong place. The classes moved in 0.1 keep their old names
 * through src/deprecated-aliases.php until 1.0, and that file names exactly them.
 */

const PLUGIN_CONTRACTS = [
    Driver::class, AuditSink::class, Authorizer::class, Caster::class, Verifier::class, ActorResolver::class,
    TenantDiscriminator::class, ReadinessProbe::class, ValueEncrypter::class, ValueValidator::class, BootstrapEvidence::class,
    Importer::class, OwnerResolver::class,
];

/** The 19 classes moved into Services\ in 0.1: old name => new name. */
const MOVED_SERVICES = [
    'Database\\AuditRepository'           => 'Services\\Database\\AuditRepository',
    'Database\\CategoryRepository'        => 'Services\\Database\\CategoryRepository',
    'Database\\DefinitionsRepository'     => 'Services\\Database\\DefinitionsRepository',
    'Database\\SchemaInspector'           => 'Services\\Database\\SchemaInspector',
    'Database\\SchemaShapeRepository'     => 'Services\\Database\\SchemaShapeRepository',
    'Stores\\SettingWriter'               => 'Services\\Stores\\SettingWriter',
    'Stores\\StoreRegistry'               => 'Services\\Stores\\StoreRegistry',
    'Resolution\\Resolver'                => 'Services\\Resolution\\Resolver',
    'Resolution\\PreloadedRecords'        => 'Services\\Resolution\\PreloadedRecords',
    'Authorization\\AccessGuard'          => 'Services\\Access\\AccessGuard',
    'Audit\\Auditor'                      => 'Services\\Access\\Auditor',
    'Configuration\\RuntimeConfiguration' => 'Services\\Configuration\\RuntimeConfiguration',
    'Definitions\\DefinitionRegistry'     => 'Services\\Definitions\\DefinitionRegistry',
    'Definitions\\DatabaseDefinitions'    => 'Services\\Definitions\\DatabaseDefinitions',
    'Tenancy\\TenantDiscriminators'       => 'Services\\Tenancy\\TenantDiscriminators',
    'Casting\\CasterRegistry'             => 'Services\\Casting\\CasterRegistry',
    'Support\\ValuePipeline'              => 'Services\\Values\\ValuePipeline',
    'Drivers\\DriverFactory'              => 'Services\\Drivers\\DriverFactory',
    'Caching\\SettingsCache'              => 'Services\\Cache\\SettingsCache',
];

/** @return array<string, string> path relative to src/ => contents */
function servicesSources(): array
{
    $root = dirname(__DIR__, 2) . '/src/';
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && $file->getFilename() !== 'deprecated-aliases.php') {
            $files[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

it('keeps contract implementations out of Services', function (): void {
    $services = array_filter(array_keys(servicesSources()), static fn (string $path): bool => str_starts_with($path, 'Services/'));
    $offenders = [];

    foreach ($services as $path) {
        $class = 'Simtabi\\Laranail\\Settings\\' . str_replace(['/', '.php'], ['\\', ''], $path);

        foreach (PLUGIN_CONTRACTS as $contract) {
            if (is_subclass_of($class, $contract)) {
                $offenders[] = $class . ' implements ' . $contract;
            }
        }
    }

    expect(count($services))->toBeGreaterThanOrEqual(25)
        ->and($offenders)->toBe([]);
});

it('files every repository and registry under Services', function (): void {
    $files = servicesSources();
    $offenders = array_keys(array_filter(
        $files,
        static fn (string $code, string $path): bool => ! str_starts_with($path, 'Services/') && preg_match('/^(?:final\s+|readonly\s+|abstract\s+)*class\s+\w+(Repository|Registry)\b/m', $code) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));
    $found = array_keys(array_filter(
        $files,
        static fn (string $code, string $path): bool => str_starts_with($path, 'Services/') && preg_match('/^(?:final\s+|readonly\s+|abstract\s+)*class\s+\w+(Repository|Registry)\b/m', $code) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));

    // The positive control: the scan does find the repositories and registries that exist.
    expect(count($files))->toBeGreaterThanOrEqual(360)
        ->and(count($found))->toBeGreaterThanOrEqual(9)
        ->and($offenders)->toBe([]);
});

it('aliases exactly the moved classes, and each old name still works', function (): void {
    $file = (string) file_get_contents(dirname(__DIR__, 2) . '/src/deprecated-aliases.php');
    preg_match_all('/^\s*(?:\\\\)?Simtabi\\\\Laranail\\\\Settings\\\\([A-Za-z\\\\]+)::class\s*=>/m', $file, $matches);
    $aliased = $matches[1];
    $base = 'Simtabi\\Laranail\\Settings\\';
    $broken = [];

    foreach (MOVED_SERVICES as $old => $new) {
        $reflection = new ReflectionClass($base . $old);

        if ($reflection->getName() !== $base . $new || ! is_a($base . $new, $base . $old, true)) {
            $broken[] = $old;
        }
    }

    expect(count(MOVED_SERVICES))->toBe(19)
        ->and($aliased)->toHaveCount(19)
        ->and(array_values(array_diff($aliased, array_keys(MOVED_SERVICES))))->toBe([])
        ->and(array_values(array_diff(array_keys(MOVED_SERVICES), $aliased)))->toBe([])
        ->and($broken)->toBe([])
        // No old file is left behind to shadow the alias.
        ->and(array_filter(array_keys(MOVED_SERVICES), static fn (string $old): bool => is_file(dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $old) . '.php')))->toBe([]);
});

it('keeps an old name working in a type declaration', function (): void {
    $accepts = static fn (StoreRegistry $registry): string => $registry::class;
    $registry = new Simtabi\Laranail\Settings\Services\Stores\StoreRegistry(
        new DriverFactory(
            Mockery::mock(Factory::class),
            Mockery::mock(Repository::class),
            new ReadOnlyValues(new ValueEncoder),
            new RecordSerializer,
            sys_get_temp_dir(),
        ),
        'settings',
        ['settings' => ['layers' => []]],
        [],
    );

    expect($accepts($registry))->toBe(Simtabi\Laranail\Settings\Services\Stores\StoreRegistry::class);
});
