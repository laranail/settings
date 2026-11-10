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
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Contracts\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Support\RecordSerializer;

/**
 * Stores records in a JSON file on a filesystem disk, one file per store.
 *
 * Writes replace the file through an atomic rename. There is no cross-process lock, so concurrent
 * writers can lose an update.
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

    public function put(StoreName $store, Record $record): void
    {
        $records = $this->read($store);
        $records[$record->identity()] = $record;
        $this->write($store, $records);
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $records = $this->read($store);
        unset($records[Record::identityOf($scope, $key, $locale)]);
        $this->write($store, $records);
    }

    public function isWritable(): bool
    {
        return $this->writable;
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

    /** Move the store's file aside after promotion and return the archive's path, if any. */
    public function archive(StoreName $store, CarbonImmutable $at): ?string
    {
        $path = $this->path($store);
        $archive = preg_replace('/\.json$/', '', $path) . '.promoted-' . $at->format('Ymd_His_u') . '.json';

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

    private function path(StoreName $store): string
    {
        return str_replace('{store}', $store->value, $this->pathTemplate);
    }
}
