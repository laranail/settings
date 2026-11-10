<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Http\ApiRoutes;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Http\OpenApi\OpenApiDocument;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.openapi`: write the OpenAPI 3.1 document of the REST API as registered (so
 * it needs `http.enabled`), to a file or standard output, for generating clients.
 */
final class OpenApiCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.openapi
        {--output= : Write the document to this file instead of standard output}';

    protected $description = 'Generate the OpenAPI document of the settings REST API';

    public function handle(Router $router, Filesystem $files): int
    {
        $routes = array_filter($router->getRoutes()->getRoutes(), static fn (Route $route): bool => str_starts_with((string) $route->getName(), ApiRoutes::NAME_PREFIX));

        if ($routes === []) {
            $this->error('The settings REST API is off: set laranail.settings.http.enabled to true first.');

            return self::FAILURE;
        }

        $json = json_encode(OpenApiDocument::build($routes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            $files->put($output, $json);
            $this->info(sprintf('Wrote the OpenAPI document (%d operation(s)) to %s.', count($routes), $output));

            return self::SUCCESS;
        }

        $this->output->write($json);

        return self::SUCCESS;
    }
}
