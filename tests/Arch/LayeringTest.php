<?php

declare(strict_types=1);

/*
 * The layering (refactor plan, 2026-11-09): entry points → actions → services → support.
 *
 *   1. An action exposes handle() and nothing else public.
 *   2. Actions never touch the database connection or schema builder themselves; repositories
 *      and drivers do.
 *   3. Only entry points depend on actions.
 *   4. Support classes hold no mutable state.
 *
 * Each exemption names its reason, and each is checked to still need its exemption, so a stale
 * entry fails rather than quietly covering something new. Every scan asserts how much it read.
 */

/** @return array<string, string> path relative to src/ => contents, for the PHP files under a src/ directory */
function sourceFiles(string $directory): array
{
    $root = dirname(__DIR__, 2) . '/src/';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

/** @return class-string the class a src/ file declares */
function classOf(string $path): string
{
    return 'Simtabi\\Laranail\\Settings\\' . str_replace(['/', '.php'], ['\\', ''], $path);
}

/** Actions with more than handle() public, and why. */
const ACTION_EXEMPTIONS = [
    'Actions\\WriteGuard'                  => 'run(): the guard every write action runs through; not an intent of its own',
    'Actions\\Categories\\CategoryGuard'   => 'run(): the guard every category action runs through',
    'Actions\\SettingActions'              => 'a bundle of the write actions for entry points (using())',
    'Actions\\StoreActions'                => 'a bundle of the store actions for entry points',
    'Actions\\Categories\\CategoryActions' => 'a bundle of the category actions for entry points (using())',
    'Actions\\QueryAudit'                  => 'get(), cursor(), paginate(): one authorized read in three result shapes, part of the public API',
    'Actions\\PruneOrphans'                => 'find(): deprecated in 0.1, forwards to FindOrphans, removed in 1.0',
];

it('gives every action handle() as its only public method', function (): void {
    $files = sourceFiles('Actions');
    $offenders = [];

    foreach (array_keys($files) as $path) {
        $class = new ReflectionClass(classOf($path));
        $public = array_values(array_filter(
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), $class->getMethods(ReflectionMethod::IS_PUBLIC)),
            static fn (string $name): bool => $name !== '__construct' && ! str_starts_with($name, '__'),
        ));
        $name = substr($class->getName(), strlen('Simtabi\\Laranail\\Settings\\'));

        if ($public !== ['handle']) {
            $offenders[$name] = $public;
        }
    }

    expect(count($files))->toBeGreaterThanOrEqual(23)
        ->and(array_keys(array_diff_key($offenders, ACTION_EXEMPTIONS)))->toBe([])
        // An exemption that no longer needs to be one is stale.
        ->and(array_keys(array_diff_key(ACTION_EXEMPTIONS, $offenders)))->toBe([]);
});

it('keeps database access out of actions', function (): void {
    $files = sourceFiles('Actions');
    $offenders = array_keys(array_filter($files, static fn (string $code): bool => preg_match('/->connection\(\)|getSchemaBuilder\(|DB::/', $code) === 1));

    expect(count($files))->toBeGreaterThanOrEqual(23)
        ->and($offenders)->toBe([]);
});

it('lets only entry points depend on actions', function (): void {
    $entryPoints = ['SettingsManager.php', 'Providers/', 'Facades/', 'Console/', 'Categories/CategoryManager.php', 'Audit/AuditQuery.php'];
    $files = sourceFiles('');
    $offenders = [];

    foreach ($files as $path => $code) {
        if (str_starts_with($path, 'Actions/') || array_any($entryPoints, static fn (string $entry): bool => str_starts_with($path, $entry))) {
            continue;
        }

        if (str_contains($code, 'use Simtabi\\Laranail\\Settings\\Actions\\')) {
            $offenders[] = $path;
        }
    }

    expect(count($files))->toBeGreaterThanOrEqual(200)
        ->and($offenders)->toBe([]);
});

it('keeps support classes free of mutable state', function (): void {
    $exempt = ['Support\\LaravelValueEncrypter' => 'resolves the application encrypter lazily and keeps it (a contract adapter)'];
    $files = sourceFiles('Support');
    $mutable = [];

    foreach (array_keys($files) as $path) {
        $class = new ReflectionClass(classOf($path));

        if (! $class->isReadOnly()) {
            $mutable[] = substr($class->getName(), strlen('Simtabi\\Laranail\\Settings\\'));
        }
    }

    expect(count($files))->toBeGreaterThanOrEqual(10)
        ->and(array_values(array_diff($mutable, array_keys($exempt))))->toBe([])
        ->and(array_values(array_diff(array_keys($exempt), $mutable)))->toBe([]);
});
