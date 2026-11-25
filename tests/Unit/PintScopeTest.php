<?php

declare(strict_types=1);

/**
 * `composer pint` checks named paths with the shared laranail style, and the published migration
 * with Laravel's stock preset, because it lands in the application. A path missing from the first
 * list would go unlinted with every check green, so every tracked PHP file must sit under one.
 */
it('lints every PHP file: the package style for the code, the Laravel preset for the migration', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $script = $composer['scripts']['pint'];

    expect($script)->toHaveCount(2)
        ->and($script[1])->toBe('@php vendor/bin/pint --preset laravel --test database/migrations');

    // tests/Fixtures is excluded by the shared config (fixture files keep deliberate shapes);
    // explicit paths bypass that exclude, so the list names the other test directories.
    $covered = [...array_slice(explode(' ', $script[0]), 3), 'database/migrations', 'tests/Fixtures'];
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules', 'tools', 'build'], true)
            && ! str_starts_with($file->getFilename(), '.'),
    ));
    $inspected = 0;
    $uncovered = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $inspected++;
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $isCovered = array_filter($covered, static fn (string $path): bool => $relative === $path || str_starts_with($relative, $path.'/'));

        if ($isCovered === []) {
            $uncovered[] = $relative;
        }
    }

    expect($inspected)->toBeGreaterThanOrEqual(400)
        ->and($uncovered)->toBe([]);
});
