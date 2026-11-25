<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Importers;

use InvalidArgumentException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\DiskImporter;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Imports laranail/toolkit's JSON settings file, flattening nested objects to dot keys. A list is
 * one value. `$source` is the file's absolute path; an empty source reads toolkit's own file from
 * its `laranail.toolkit.settings` disk and path. {@see readFromDisk()} reads any disk.
 */
final readonly class ToolkitJsonImporter implements DiskImporter
{
    /** Toolkit's own defaults, used when its config is not loaded (the package need not be installed). */
    public const string DEFAULT_DISK = 'local';

    public const string DEFAULT_PATH = 'laranail/settings.json';

    public function __construct(
        private Filesystem $files,
        private FilesystemFactory $disks,
        private Repository $config,
    ) {}

    public function read(string $source, StoreName $store): ExportDocument
    {
        if ($source === '') {
            return $this->readFromDisk(null, null, $store);
        }

        if (! $this->files->isFile($source)) {
            throw new InvalidImport(sprintf('toolkit: [%s] is not a file.', $source));
        }

        return $this->document($this->files->get($source), $source, $store);
    }

    public function readFromDisk(?string $disk, ?string $path, StoreName $store): ExportDocument
    {
        $disk ??= $this->configured('disk', self::DEFAULT_DISK);
        $path ??= $this->configured('path', self::DEFAULT_PATH);

        try {
            $filesystem = $this->disks->disk($disk);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidImport(sprintf('toolkit: disk [%s] cannot be used (%s).', $disk, $exception->getMessage()), 0, $exception);
        }

        $json = $filesystem->exists($path) ? $filesystem->get($path) : null;

        if ($json === null) {
            throw new InvalidImport(sprintf('toolkit: [%s] does not exist on disk [%s].', $path, $disk));
        }

        return $this->document($json, $disk . ':' . $path, $store);
    }

    private function document(string $json, string $label, StoreName $store): ExportDocument
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidImport(sprintf('toolkit: [%s] is not a JSON object.', $label));
        }

        $entries = [];

        foreach ($this->flatten($data) as [$key, $value]) {
            $entries[] = [$key, $value, false];
        }

        return ImportedKeys::document($store, ImportedKeys::values($entries, 'toolkit'), 'toolkit');
    }

    /** Toolkit's `laranail.toolkit.settings.<name>`, read only if set, so toolkit is not needed. */
    private function configured(string $name, string $default): string
    {
        $value = $this->config->get('laranail.toolkit.settings.' . $name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Every leaf as [dot path, value], keeping a nested and a dotted spelling of the same path
     * apart so the collision check sees both.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<array{string, mixed}>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                array_push($flat, ...$this->flatten($value, $path));
            } else {
                $flat[] = [$path, $value];
            }
        }

        return $flat;
    }
}
