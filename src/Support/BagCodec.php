<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Throwable;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;

/**
 * Converts one scope's records to and from the plain array the cache holds. A payload that does
 * not decode reads as a cache miss, never as an empty scope.
 */
final readonly class BagCodec
{
    /** Bump when the encoded shape changes, so entries from another release are misses. */
    public const int VERSION = 1;

    /** @return array{v: int, r: list<array<string, mixed>>} */
    public function encode(RecordSet $records): array
    {
        return ['v' => self::VERSION, 'r' => array_map(static fn (Record $record): array => [
            's' => $record->scope->value,
            'k' => $record->key->value,
            'l' => $record->locale->value,
            'p' => $record->payload,
            't' => $record->type,
            'e' => $record->encrypted,
            'x' => $record->locked,
            'n' => $record->version,
            'w' => $record->writtenAt?->toIso8601String(),
            'g' => $record->group,
            'c' => $record->category,
            'u' => $record->public,
        ], $records->all())];
    }

    /** The records an entry holds, or null when this release did not write it. */
    public function decode(mixed $entry): ?RecordSet
    {
        if (! is_array($entry) || ($entry['v'] ?? null) !== self::VERSION || ! is_array($entry['r'] ?? null)) {
            return null;
        }

        $records = [];

        foreach ($entry['r'] as $row) {
            $record = is_array($row) ? $this->record($row) : null;

            if (! $record instanceof Record) {
                return null;
            }

            $records[] = $record;
        }

        return new RecordSet($records);
    }

    /** @param array<array-key, mixed> $row */
    private function record(array $row): ?Record
    {
        $scope = is_string($row['s'] ?? null) ? Scope::fromString($row['s']) : null;
        $payload = $row['p'] ?? null;
        $written = $row['w'] ?? null;

        if (! $scope instanceof Scope || ! is_string($row['k'] ?? null) || ! is_string($row['l'] ?? null) || ! is_int($row['n'] ?? null)
            || ($payload !== null && ! is_string($payload)) || ($written !== null && ! is_string($written))
            || ! $this->nullableStrings($row, ['t', 'g', 'c']) || ! $this->booleans($row, ['e', 'x', 'u'])) {
            return null;
        }

        try {
            return new Record(
                scope: $scope,
                key: new Key($row['k']),
                locale: new Locale($row['l']),
                payload: $payload,
                type: $this->nullableString($row['t']),
                encrypted: $row['e'] === true,
                locked: $row['x'] === true,
                version: $row['n'],
                writtenAt: $written === null ? null : CarbonImmutable::parse($written),
                group: $this->nullableString($row['g']),
                category: $this->nullableString($row['c']),
                public: $row['u'] === true,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<string> $fields
     */
    private function nullableStrings(array $row, array $fields): bool
    {
        return array_all($fields, static fn (string $field): bool => array_key_exists($field, $row) && ($row[$field] === null || is_string($row[$field])));
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<string> $fields
     */
    private function booleans(array $row, array $fields): bool
    {
        return array_all($fields, static fn (string $field): bool => is_bool($row[$field] ?? null));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
