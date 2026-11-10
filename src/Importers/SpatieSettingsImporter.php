<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Importers;

use Exception;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Importer;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/**
 * Imports spatie/laravel-settings' table: each row becomes the global key `<group>.<name>`.
 * Encrypted values arrive as spatie's ciphertext and must be set again. `$source` is
 * `<connection>` or `<connection>:<table>` (default table `settings`).
 */
final readonly class SpatieSettingsImporter implements Importer
{
    public function __construct(
        private ConnectionResolverInterface $db,
    ) {}

    public function read(string $source, StoreName $store): ExportDocument
    {
        [$connection, $table] = array_pad(explode(':', $source, 2), 2, null);
        $table = $table === null || $table === '' ? 'settings' : $table;

        try {
            $rows = $this->db->connection($connection === '' ? null : $connection)->table($table)->get(['group', 'name', 'payload', 'locked'])->all();
        } catch (Exception $exception) {
            throw new InvalidImport(sprintf('spatie: table [%s] on connection [%s] is not a spatie/laravel-settings table (%s).', $table, $connection ?: 'default', $exception->getMessage()), 0, $exception);
        }

        $values = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $group = is_string($row['group'] ?? null) ? $row['group'] : '';
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $key = ImportedKeys::key($group . '.' . $name, 'spatie');

            $values[$key->value] = [$payload, (bool) ($row['locked'] ?? false)];
        }

        return ImportedKeys::document($store, $values, 'spatie');
    }
}
