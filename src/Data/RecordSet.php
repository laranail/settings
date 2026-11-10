<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Countable;

/** The records one layer returned, indexed by scope, key and locale. */
final readonly class RecordSet implements Countable
{
    /** @var array<string, Record> */
    private array $records;

    /** @param iterable<Record> $records */
    public function __construct(iterable $records = [])
    {
        $indexed = [];

        foreach ($records as $record) {
            $indexed[$record->identity()] = $record;
        }

        $this->records = $indexed;
    }

    public function find(Scope $scope, Key $key, Locale $locale): ?Record
    {
        return $this->records[Record::identityOf($scope, $key, $locale)] ?? null;
    }

    /** @return list<Record> */
    public function all(): array
    {
        return array_values($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }
}
