<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Container\Attributes\Singleton;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;

/**
 * Converts records to and from the plain arrays file layers store.
 */
#[Singleton]
final readonly class RecordSerializer
{
    /** @return array<string, mixed> */
    public function toArray(Record $record): array
    {
        return [
            'scope'      => $record->scope->value,
            'key'        => $record->key->value,
            'locale'     => $record->locale->value,
            'payload'    => $record->payload,
            'type'       => $record->type,
            'encrypted'  => $record->encrypted,
            'locked'     => $record->locked,
            'version'    => $record->version,
            'written_at' => $record->writtenAt?->toIso8601String(),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public function fromArray(array $row, string $source): Record
    {
        $scope = $row['scope'] ?? null;
        $key = $row['key'] ?? null;
        $locale = $row['locale'] ?? '';
        $payload = $row['payload'] ?? null;
        $type = $row['type'] ?? null;
        $version = $row['version'] ?? 1;
        $writtenAt = $row['written_at'] ?? null;

        if (! is_string($scope) || ! is_string($key) || ! is_string($locale) || ($payload !== null && ! is_string($payload))
            || ($type !== null && ! is_string($type)) || ! is_int($version) || ($writtenAt !== null && ! is_string($writtenAt))) {
            throw new DriverFailure(sprintf('A record in [%s] is malformed.', $source));
        }

        return new Record(
            scope: $this->scope($scope, $source),
            key: new Key($key),
            locale: new Locale($locale),
            payload: $payload,
            type: $type,
            encrypted: ($row['encrypted'] ?? false) === true,
            locked: ($row['locked'] ?? false) === true,
            version: $version,
            writtenAt: $writtenAt === null ? null : CarbonImmutable::parse($writtenAt),
        );
    }

    private function scope(string $value, string $source): Scope
    {
        return Scope::fromString($value) ?? throw new DriverFailure(sprintf('A record in [%s] has a malformed scope.', $source));
    }
}
