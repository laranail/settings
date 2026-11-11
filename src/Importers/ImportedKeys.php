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
     * Normalise every source key and refuse the whole source when two or more of them land on
     * the same settings key, naming each colliding group, so nothing is overwritten in silence.
     * The check runs before any document exists, so a dry run and an applied import fail alike.
     *
     * @param list<array{string, mixed, bool}> $entries [source key, value, locked], in source order
     *
     * @return array<string, array{mixed, bool}> key => [value, locked]
     *
     * @throws InvalidImport when a key cannot be made valid, or keys collide
     */
    public static function values(array $entries, string $source): array
    {
        $values = [];
        $origins = [];

        foreach ($entries as [$sourceKey, $value, $locked]) {
            $key = self::key($sourceKey, $source)->value;
            $values[$key] = [$value, $locked];
            $origins[$key][] = $sourceKey;
        }

        $collisions = array_filter($origins, static fn (array $keys): bool => count($keys) > 1);

        if ($collisions !== []) {
            ksort($collisions);
            $groups = array_map(static fn (string $key, array $keys): string => sprintf('[%s] -> %s', implode(', ', $keys), $key), array_keys($collisions), $collisions);

            throw new InvalidImport(sprintf('%s: %d %s of source keys collide after normalising; nothing was imported: %s.', $source, count($collisions), count($collisions) === 1 ? 'group' : 'groups', implode('; ', $groups)));
        }

        return $values;
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
