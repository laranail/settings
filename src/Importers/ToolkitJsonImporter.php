<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Importers;

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Importer;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/**
 * Imports laranail/toolkit's JSON settings file, flattening nested objects to dot keys. A list is
 * one value. `$source` is the file's absolute path.
 */
final readonly class ToolkitJsonImporter implements Importer
{
    public function __construct(
        private Filesystem $files,
    ) {}

    public function read(string $source, StoreName $store): ExportDocument
    {
        if (! $this->files->isFile($source)) {
            throw new InvalidImport(sprintf('toolkit: [%s] is not a file.', $source));
        }

        $data = json_decode($this->files->get($source), true);

        if (! is_array($data)) {
            throw new InvalidImport(sprintf('toolkit: [%s] is not a JSON object.', $source));
        }

        $values = [];

        foreach ($this->flatten($data) as $key => $value) {
            $values[ImportedKeys::key($key, 'toolkit')->value] = [$value, false];
        }

        return ImportedKeys::document($store, $values, 'toolkit');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
