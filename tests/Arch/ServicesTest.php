<?php

declare(strict_types=1);
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Contracts\Importer;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;

/*
 * Stateful collaborators that own I/O or a body of rules live under Services\, and nowhere else.
 * Contract implementations stay beside their kind, so nothing under Services\ implements a plug-in
 * contract, and no class resolves under the namespaces it moved out of.
 */

const PLUGIN_CONTRACTS = [
    Driver::class, AuditSink::class, Authorizer::class, Caster::class, Verifier::class, ActorResolver::class,
    TenantDiscriminator::class, Importer::class,
];

/** The 19 classes moved into Services\ in 0.1, whose old names were removed: old name => new name. */
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
    expect(count($files))->toBeGreaterThanOrEqual(355)
        ->and(count($found))->toBeGreaterThanOrEqual(9)
        ->and($offenders)->toBe([]);
});

it('leaves no old name for a moved class, and every new name loads', function (): void {
    $base = 'Simtabi\\Laranail\\Settings\\';
    $root = dirname(__DIR__, 2);

    expect(count(MOVED_SERVICES))->toBe(19)
        ->and(is_file($root . '/src/deprecated-aliases.php'))->toBeFalse()
        ->and(array_keys(array_filter(MOVED_SERVICES, static fn (string $new, string $old): bool => class_exists($base . $old), ARRAY_FILTER_USE_BOTH)))->toBe([])
        ->and(array_values(array_filter(MOVED_SERVICES, static fn (string $new): bool => ! class_exists($base . $new))))->toBe([]);
});
