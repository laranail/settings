<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Throwable;
use JsonException;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Filesystem\FilesystemAdapter;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Contracts\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Contracts\Driver;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;

/**
 * Stores records in a JSON file on a filesystem disk, one file per store.
 *
 * Writes replace the file through an atomic rename, so a reader never sees half a file. On a local
 * disk each read-modify-write holds an exclusive `flock` on a sidecar `<file>.lock`, and a write
 * is version-checked like the database layer's: a writer whose base version is stale gets
 * {@see ConcurrentModification} instead of overwriting. Reads take no lock. A disk that is not
 * local (S3 and the like) has no lock, so the version check is all that guards it.
 */
final readonly class JsonDriver implements Driver
{
    public function __construct(
        private Filesystem $disk,
        private string $pathTemplate,
        private bool $writable,
        private RecordSerializer $serializer,
    ) {}

    public function load(StoreName $store, array $scopes): RecordSet
    {
        $wanted = array_map(static fn (Scope $scope): string => $scope->value, $scopes);

        return new RecordSet(array_filter(
            $this->read($store),
            static fn (Record $record): bool => in_array($record->scope->value, $wanted, true),
        ));
    }

    /**
     * Store a record one version above the version the writer read.
     *
     * @throws ConcurrentModification when the file holds another version than the writer read
     */
    public function put(StoreName $store, Record $record): void
    {
        $this->locked($store, function () use ($store, $record): void {
            $records = $this->read($store);
            $current = $records[$record->identity()] ?? null;

            // As the database layer: a record must be at the version below; a new one may start anywhere.
            if ($current instanceof Record && $current->version !== $record->version - 1) {
                throw new ConcurrentModification(sprintf('Setting [%s] in store [%s] for scope [%s] is at version %d in its file, not %d; re-read and retry.', $record->key, $store, $record->scope, $current->version, $record->version - 1));
            }

            $records[$record->identity()] = $record;
            $this->write($store, $records);
        });
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $this->locked($store, function () use ($store, $scope, $key, $locale): void {
            $records = $this->read($store);
            unset($records[Record::identityOf($scope, $key, $locale)]);
            $this->write($store, $records);
        });
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /** Whether the store's file exists: one existence check (an `is_file()` on a local disk). */
    public function hasFile(StoreName $store): bool
    {
        try {
            return $this->disk->exists($this->path($store));
        } catch (Throwable $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be read.', $this->path($store)), $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Every record in the store's file.
     *
     * @return list<Record>
     */
    public function all(StoreName $store): array
    {
        return array_values($this->read($store));
    }

    /**
     * Every record in another file on the same disk; the path may not leave the disk.
     *
     * @return list<Record>
     */
    public function recordsIn(string $path): array
    {
        if (str_contains($path, '..')) {
            throw new DriverFailure(sprintf('Settings file [%s] must not contain "..".', $path));
        }

        try {
            $exists = $this->disk->exists($path);
        } catch (Throwable $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be read.', $path), $exception->getCode(), previous: $exception);
        }

        return $exists ? array_values($this->readPath($path)) : throw new DriverFailure(sprintf('Settings file [%s] does not exist.', $path));
    }

    /**
     * Copy the store's file aside, then replace it with only these records in one atomic rename:
     * what a selective promotion leaves in the file. Returns the archive's path.
     *
     * @param list<Record> $kept
     */
    public function archiveKeeping(StoreName $store, CarbonImmutable $at, array $kept): string
    {
        $path = $this->path($store);
        $archive = $this->archivePath($store, $at);

        try {
            if (! $this->disk->copy($path, $archive)) {
                throw new DriverFailure(sprintf('Settings file [%s] could not be archived.', $path));
            }
        } catch (DriverFailure $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be archived.', $path), $exception->getCode(), previous: $exception);
        }

        $records = [];

        foreach ($kept as $record) {
            $records[$record->identity()] = $record;
        }

        $this->locked($store, fn () => $this->write($store, $records));

        return $archive;
    }

    /** Move the store's file aside after promotion and return the archive's path, if any. */
    public function archive(StoreName $store, CarbonImmutable $at): ?string
    {
        $path = $this->path($store);
        $archive = $this->archivePath($store, $at);

        try {
            if (! $this->disk->exists($path)) {
                return null;
            }

            if (! $this->disk->move($path, $archive)) {
                throw new DriverFailure(sprintf('Settings file [%s] could not be archived.', $path));
            }
        } catch (DriverFailure $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be archived.', $path), $exception->getCode(), previous: $exception);
        }

        return $archive;
    }

    /** @return array<string, Record> */
    private function read(StoreName $store): array
    {
        return $this->readPath($this->path($store));
    }

    /** @return array<string, Record> */
    private function readPath(string $path): array
    {
        try {
            if (! $this->disk->exists($path)) {
                return [];
            }

            $decoded = json_decode((string) $this->disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] is not valid JSON.', $path), $exception->getCode(), previous: $exception);
        } catch (Throwable $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be read.', $path), $exception->getCode(), previous: $exception);
        }

        $rows = is_array($decoded) ? ($decoded['records'] ?? null) : null;

        if (! is_array($rows)) {
            throw new DriverFailure(sprintf('Settings file [%s] has no "records" list.', $path));
        }

        $records = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DriverFailure(sprintf('Settings file [%s] holds a malformed record.', $path));
            }

            $record = $this->serializer->fromArray($row, $path);
            $records[$record->identity()] = $record;
        }

        return $records;
    }

    /** @param array<string, Record> $records */
    private function write(StoreName $store, array $records): void
    {
        $path = $this->path($store);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            $json = json_encode(
                ['records' => array_values(array_map($this->serializer->toArray(...), $records))],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (! $this->disk->put($temporary, $json) || ! $this->disk->move($temporary, $path)) {
                throw new DriverFailure(sprintf('Settings file [%s] could not be written.', $path));
            }
        } catch (DriverFailure $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be written.', $path), $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Run a read-modify-write under an exclusive lock on the store's sidecar lock file, released
     * whatever happens. A disk that is not local runs it unlocked.
     *
     * @param callable(): void $work
     */
    private function locked(StoreName $store, callable $work): void
    {
        $lockPath = $this->localPath($this->path($store) . '.lock');

        if ($lockPath === null) {
            $work();

            return;
        }

        $directory = dirname($lockPath);
        $handle = (is_dir($directory) || @mkdir($directory, 0o755, true)) ? @fopen($lockPath, 'c') : false;

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new DriverFailure(sprintf('Settings file [%s] could not be locked for writing.', $this->path($store)));
        }

        try {
            $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** The absolute path of a file on a local disk, or null when the disk is not local. */
    private function localPath(string $path): ?string
    {
        return $this->disk instanceof FilesystemAdapter && $this->disk->getAdapter() instanceof LocalFilesystemAdapter
            ? $this->disk->path($path)
            : null;
    }

    private function archivePath(StoreName $store, CarbonImmutable $at): string
    {
        return preg_replace('/\.json$/', '', $this->path($store)) . '.promoted-' . $at->format('Ymd_His_u') . '.json';
    }

    private function path(StoreName $store): string
    {
        return str_replace('{store}', $store->value, $this->pathTemplate);
    }
}
