<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Contracts\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;

/**
 * One suite over every built-in driver. The driver list is DriverType::cases(), not a
 * hand-written array, and the count is asserted so a renamed enum cannot empty the suite.
 */
function driverFor(DriverType $type): Driver
{
    $path = sys_get_temp_dir() . '/laranail-contract-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($path, "<?php return ['site.name' => 'Shipped'];");
    config()->set('contract', ['site' => ['name' => 'Configured']]);

    if ($type === DriverType::Database) {
        useDatabase(stores: ['one', 'two']);
    }

    return app(DriverFactory::class)->make(match ($type) {
        DriverType::Database => new LayerConfig('d', 'database', true),
        DriverType::Array    => new LayerConfig('a', 'array', true),
        DriverType::Json     => new LayerConfig('j', 'json', true, ['disk' => 'local', 'path' => 'contract/{store}.json']),
        DriverType::Php      => new LayerConfig('p', 'php', false, ['path' => $path]),
        DriverType::Config   => new LayerConfig('c', 'config', false, ['prefix' => 'contract']),
    });
}

it('covers every built-in driver', function (): void {
    expect(DriverType::cases())->toHaveCount(5);
});

it('returns only the scopes asked for', function (DriverType $type): void {
    $driver = driverFor($type);
    $store = new StoreName('settings');
    $owner = new OwnerRef('user', 1)->scope();

    if ($driver->isWritable()) {
        $driver->put($store, new Record(Scope::global(), new Key('site.name'), Locale::none(), '"Global"'));
        $driver->put($store, new Record($owner, new Key('site.name'), Locale::none(), '"Owner"'));
    }

    expect($driver->load($store, [Scope::global()])->find(Scope::global(), new Key('site.name'), Locale::none()))->not->toBeNull()
        ->and($driver->load($store, [$owner])->find(Scope::global(), new Key('site.name'), Locale::none()))->toBeNull();
})->with(DriverType::cases());

it('round-trips a record through put, load and forget when writable', function (DriverType $type): void {
    $driver = driverFor($type);
    $store = new StoreName('settings');
    $key = new Key('ui.theme');
    $scope = new OwnerRef('user', 9)->scope();

    if (! $driver->isWritable()) {
        expect(fn () => $driver->put($store, new Record($scope, $key, Locale::none(), '"dark"')))->toThrow(StoreNotWritable::class)
            ->and(fn () => $driver->forget($store, $scope, $key, Locale::none()))->toThrow(StoreNotWritable::class);

        return;
    }

    $driver->put($store, new Record($scope, $key, new Locale('fr'), '"sombre"', type: 'string', locked: true, version: 3));
    $loaded = $driver->load($store, [$scope])->find($scope, $key, new Locale('fr'));

    expect($loaded?->payload)->toBe('"sombre"')
        ->and($loaded?->type)->toBe('string')
        ->and($loaded?->locked)->toBeTrue()
        ->and($loaded?->version)->toBe(3);

    $driver->forget($store, $scope, $key, new Locale('fr'));

    expect($driver->load($store, [$scope])->count())->toBe(0);
})->with(DriverType::cases());

it('keeps stores apart in the same driver', function (DriverType $type): void {
    $driver = driverFor($type);

    if (! $driver->isWritable()) {
        expect(true)->toBeTrue();

        return;
    }

    $driver->put(new StoreName('one'), new Record(Scope::global(), new Key('k'), Locale::none(), '1'));

    expect($driver->load(new StoreName('two'), [Scope::global()])->count())->toBe(0);
})->with(DriverType::cases());

it('builds a custom driver registered with a closure and refuses to replace a built-in one', function (): void {
    $factory = app(DriverFactory::class);
    $factory->extend('memory', static fn (LayerConfig $layer): Driver => driverFor(DriverType::Array));

    expect($factory->make(new LayerConfig('m', 'memory', true)))->toBeInstanceOf(Driver::class)
        ->and($factory->names())->toContain('memory', 'json')
        ->and(fn () => $factory->extend('json', static fn (LayerConfig $layer): Driver => driverFor(DriverType::Array)))
        ->toThrow(InvalidConfiguration::class, 'is built in');
});

/** A JSON layer on a fresh local disk of its own, for the concurrency tests. */
function freshJsonDriver(): Driver
{
    $root = sys_get_temp_dir() . '/laranail-json-' . bin2hex(random_bytes(6));
    config()->set('filesystems.disks.json-concurrency', ['driver' => 'local', 'root' => $root]);

    return app(DriverFactory::class)->make(new LayerConfig('j', 'json', true, ['disk' => 'json-concurrency', 'path' => 'settings/{store}.json']));
}

it('refuses a JSON write whose base version is stale, so no update is lost (SP-09)', function (): void {
    $driver = freshJsonDriver();
    $store = new StoreName('settings');
    $key = new Key('site.name');

    $driver->put($store, new Record(Scope::global(), $key, Locale::none(), '"first"', version: 1));
    // Two writers both read version 1; the first lands version 2.
    $driver->put($store, new Record(Scope::global(), $key, Locale::none(), '"writer a"', version: 2));

    expect(fn () => $driver->put($store, new Record(Scope::global(), $key, Locale::none(), '"writer b"', version: 2)))->toThrow(ConcurrentModification::class, 'version 2 in its file, not 1')
        ->and($driver->load($store, [Scope::global()])->find(Scope::global(), $key, Locale::none())?->payload)->toBe('"writer a"');

    // The lock was released by the refused write: the next one goes through.
    $driver->put($store, new Record(Scope::global(), $key, Locale::none(), '"writer b again"', version: 3));

    expect($driver->load($store, [Scope::global()])->find(Scope::global(), $key, Locale::none())?->version)->toBe(3);
});

it('loses no record when two processes write one JSON file at once (SP-09)', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $driver = freshJsonDriver();
    $store = new StoreName('settings');
    $driver->put($store, new Record(Scope::global(), new Key('seed'), Locale::none(), '0', version: 1));
    $writes = 40;
    $pid = pcntl_fork();

    if ($pid === 0) {
        for ($index = 0; $index < $writes; $index++) {
            $driver->put($store, new Record(Scope::global(), new Key('child.k' . $index), Locale::none(), '1', version: 1));
        }

        // End the child without running the parent's shutdown and test teardown.
        posix_kill(getmypid(), SIGKILL);
    }

    for ($index = 0; $index < $writes; $index++) {
        $driver->put($store, new Record(Scope::global(), new Key('parent.k' . $index), Locale::none(), '1', version: 1));
    }

    pcntl_waitpid($pid, $status);

    expect($driver->load($store, [Scope::global()])->count())->toBe(2 * $writes + 1);
});

it('reports a JSON lock it cannot take, and still version-checks a disk with no local path (SP-09)', function (): void {
    $root = sys_get_temp_dir() . '/laranail-json-' . bin2hex(random_bytes(6));
    config()->set('filesystems.disks.json-lockless', ['driver' => 'local', 'root' => $root]);
    $driver = app(DriverFactory::class)->make(new LayerConfig('j', 'json', true, ['disk' => 'json-lockless', 'path' => 'settings/{store}.json']));
    // A directory where the lock file belongs: it cannot be opened, so the write is refused.
    mkdir($root . '/settings/settings.json.lock', 0o755, true);

    expect(fn () => $driver->put(new StoreName('settings'), new Record(Scope::global(), new Key('a'), Locale::none(), '1', version: 1)))->toThrow(DriverFailure::class, 'could not be locked');

    // A disk that is not local: no lock, but the version check still refuses a stale write.
    $files = [];
    $disk = Mockery::mock(Filesystem::class);
    $disk->allows('exists')->andReturnUsing(static function (string $path) use (&$files): bool {
        return isset($files[$path]);
    });
    $disk->allows('get')->andReturnUsing(static function (string $path) use (&$files): string {
        return $files[$path];
    });
    $disk->allows('put')->andReturnUsing(static function (string $path, string $contents) use (&$files): bool {
        $files[$path] = $contents;

        return true;
    });
    $disk->allows('move')->andReturnUsing(static function (string $from, string $to) use (&$files): bool {
        $files[$to] = $files[$from];
        unset($files[$from]);

        return true;
    });
    $remote = new JsonDriver($disk, 'settings/{store}.json', true, app(RecordSerializer::class));
    $remote->put(new StoreName('settings'), new Record(Scope::global(), new Key('a'), Locale::none(), '1', version: 1));
    $remote->put(new StoreName('settings'), new Record(Scope::global(), new Key('a'), Locale::none(), '2', version: 2));

    expect(fn () => $remote->put(new StoreName('settings'), new Record(Scope::global(), new Key('a'), Locale::none(), '3', version: 2)))->toThrow(ConcurrentModification::class)
        ->and(array_keys($files))->toBe(['settings/settings.json']);
});

it('reports every JSON disk failure as DriverFailure, never as the disk\'s own exception', function (): void {
    $broken = Mockery::mock(Filesystem::class);
    $broken->allows('exists')->andThrow(new RuntimeException('disk gone'));
    $broken->allows('copy')->andThrow(new RuntimeException('disk gone'));
    $driver = new JsonDriver($broken, 'settings/{store}.json', true, app(RecordSerializer::class));
    $store = new StoreName('settings');

    expect(fn (): bool => $driver->hasFile($store))->toThrow(DriverFailure::class, 'could not be read')
        ->and(fn (): array => $driver->recordsIn('../etc/passwd'))->toThrow(DriverFailure::class, 'must not contain')
        ->and(fn (): array => $driver->recordsIn('settings/other.json'))->toThrow(DriverFailure::class, 'could not be read')
        ->and(fn (): ?string => $driver->archive($store, now()->toImmutable()))->toThrow(DriverFailure::class, 'could not be archived')
        ->and(fn (): string => $driver->archiveKeeping($store, now()->toImmutable(), []))->toThrow(DriverFailure::class, 'could not be archived');

    $refusing = Mockery::mock(Filesystem::class);
    $refusing->allows('exists')->andReturn(true);
    $refusing->allows('get')->andReturn('{"records": ["not a record"]}');
    $refusing->allows('copy')->andReturn(false);
    $refusing->allows('move')->andReturn(false);
    $refusing->allows('put')->andThrow(new RuntimeException('read-only'));
    $driver = new JsonDriver($refusing, 'settings/{store}.json', true, app(RecordSerializer::class));

    expect(fn (): RecordSet => $driver->load($store, [Scope::global()]))->toThrow(DriverFailure::class, 'malformed record')
        ->and(fn (): ?string => $driver->archive($store, now()->toImmutable()))->toThrow(DriverFailure::class, 'could not be archived')
        ->and(fn (): string => $driver->archiveKeeping($store, now()->toImmutable(), []))->toThrow(DriverFailure::class, 'could not be archived')
        ->and(fn () => $driver->forget($store, Scope::global(), new Key('a'), Locale::none()))->toThrow(DriverFailure::class, 'malformed record');

    $readOnly = Mockery::mock(Filesystem::class);
    $readOnly->allows('exists')->andReturn(false);
    $readOnly->allows('put')->andThrow(new RuntimeException('read-only'));
    $driver = new JsonDriver($readOnly, 'settings/{store}.json', true, app(RecordSerializer::class));

    expect(fn () => $driver->forget($store, Scope::global(), new Key('a'), Locale::none()))->toThrow(DriverFailure::class, 'could not be written');
});
