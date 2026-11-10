<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Importers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Data\Transfer\ExportedSetting;

/**
 * Normalises another package's keys and gathers global values into an export document.
 */
final readonly class ImportedKeys
{
    /** @throws InvalidImport when the key cannot be made valid */
    public static function key(string $key, string $source): Key
    {
        try {
            return new Key(str_replace(['-', ' '], '_', strtolower(trim($key))));
        } catch (SettingsException $exception) {
            throw new InvalidImport(sprintf('%s: [%s] cannot be a settings key (%s).', $source, $key, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * Global values as an export document.
     *
     * @param array<string, array{mixed, bool}> $values key => [value, locked]
     */
    public static function document(StoreName $store, array $values, string $source): ExportDocument
    {
        ksort($values);
        $settings = [];

        foreach ($values as $key => [$value, $locked]) {
            $settings[] = new ExportedSetting(Scope::global(), new Key($key), Locale::none(), $value, locked: $locked);
        }

        return new ExportDocument($store->value, [], $settings, ExportMode::Plaintext, null, $source);
    }
}
