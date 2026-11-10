<?php

declare(strict_types=1);

/*
 * laranail/settings: the old names of the classes moved into the Services namespace in 0.1.
 *
 * Each old name still resolves to the moved class, so code that imports it, type-hints it,
 * resolves it from the container or checks `instanceof` against it keeps working. The aliases
 * are declared eagerly (when Composer loads this file) because PHP does not autoload a class for
 * a type check: a lazily declared alias would make `function f(OldName $x)` reject the very
 * object it used to accept. For the same reason no deprecation notice is raised here (an eager
 * file cannot tell whether the old name is ever used); the deprecation is recorded in
 * CHANGELOG.md and docs/architecture.md instead.
 *
 * @deprecated every alias below is deprecated since 0.1 and removed in 1.0; import the new name.
 */

use Simtabi\Laranail\Settings\Services\Access\Auditor;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Services\Resolution\Resolver;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Services\Database\AuditRepository;
use Simtabi\Laranail\Settings\Services\Database\SchemaInspector;
use Simtabi\Laranail\Settings\Services\Database\CategoryRepository;
use Simtabi\Laranail\Settings\Services\Resolution\PreloadedRecords;
use Simtabi\Laranail\Settings\Services\Tenancy\TenantDiscriminators;
use Simtabi\Laranail\Settings\Services\Database\DefinitionsRepository;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Services\Definitions\DatabaseDefinitions;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

foreach ([
    Simtabi\Laranail\Settings\Database\AuditRepository::class           => AuditRepository::class,
    Simtabi\Laranail\Settings\Database\CategoryRepository::class        => CategoryRepository::class,
    Simtabi\Laranail\Settings\Database\DefinitionsRepository::class     => DefinitionsRepository::class,
    Simtabi\Laranail\Settings\Database\SchemaInspector::class           => SchemaInspector::class,
    Simtabi\Laranail\Settings\Database\SchemaShapeRepository::class     => SchemaShapeRepository::class,
    Simtabi\Laranail\Settings\Stores\SettingWriter::class               => SettingWriter::class,
    Simtabi\Laranail\Settings\Stores\StoreRegistry::class               => StoreRegistry::class,
    Simtabi\Laranail\Settings\Resolution\Resolver::class                => Resolver::class,
    Simtabi\Laranail\Settings\Resolution\PreloadedRecords::class        => PreloadedRecords::class,
    Simtabi\Laranail\Settings\Authorization\AccessGuard::class          => AccessGuard::class,
    Simtabi\Laranail\Settings\Audit\Auditor::class                      => Auditor::class,
    Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration::class => RuntimeConfiguration::class,
    Simtabi\Laranail\Settings\Definitions\DefinitionRegistry::class     => DefinitionRegistry::class,
    Simtabi\Laranail\Settings\Definitions\DatabaseDefinitions::class    => DatabaseDefinitions::class,
    Simtabi\Laranail\Settings\Tenancy\TenantDiscriminators::class       => TenantDiscriminators::class,
    Simtabi\Laranail\Settings\Casting\CasterRegistry::class             => CasterRegistry::class,
    Simtabi\Laranail\Settings\Support\ValuePipeline::class              => ValuePipeline::class,
    Simtabi\Laranail\Settings\Drivers\DriverFactory::class              => DriverFactory::class,
    Simtabi\Laranail\Settings\Caching\SettingsCache::class              => SettingsCache::class,
] as $deprecated => $moved) {
    if (! class_exists($deprecated, false)) {
        class_alias($moved, $deprecated);
    }
}
