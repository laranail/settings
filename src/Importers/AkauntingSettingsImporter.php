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
 * akaunting/laravel-setting's database driver (G-43; the key-value packages of research.md §2):
 * reads its table of `key` / `value` rows, where nested values are already flattened to dot
 * keys and every value is a string. Each row becomes a global key with that string value; define
 * a typed definition here before importing to have it cast. `$source` is `<connection>` or
 * `<connection>:<table>` (default table `settings`). Rows its extra columns scope to a model or
 * team are skipped, because that scoping is the application's own.
 */
final readonly class AkauntingSettingsImporter implements Importer
{
    public function __construct(
        private ConnectionResolverInterface $db,
    ) {}

    public function read(string $source, StoreName $store): ExportDocument
    {
        [$connection, $table] = array_pad(explode(':', $source, 2), 2, null);
        $table = $table === null || $table === '' ? 'settings' : $table;

        try {
            $rows = $this->db->connection($connection === '' ? null : $connection)->table($table)->get()->all();
        } catch (Exception $exception) {
            throw new InvalidImport(sprintf('akaunting: table [%s] on connection [%s] cannot be read (%s).', $table, $connection ?: 'default', $exception->getMessage()), 0, $exception);
        }

        $values = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            if (! array_key_exists('key', $row) || ! array_key_exists('value', $row)) {
                throw new InvalidImport(sprintf('akaunting: table [%s] has no key and value columns.', $table));
            }

            // Extra columns (user_id, company_id, …) scope a row to something of the host's own.
            if (array_filter(array_diff_key($row, array_flip(['id', 'key', 'value', 'created_at', 'updated_at'])), static fn (mixed $value): bool => $value !== null) !== []) {
                continue;
            }

            $values[ImportedKeys::key(is_string($row['key']) ? $row['key'] : '', 'akaunting')->value] = [$row['value'], false];
        }

        return ImportedKeys::document($store, $values, 'akaunting');
    }
}
