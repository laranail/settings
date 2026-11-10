<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use BackedEnum;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;

/**
 * A package event flattened to identifiers, for the operational log and for alerts: scalar
 * properties as they are, a Subject as store / scope / key / locale / category, an Actor as
 * `actor` (type, alias and id), an enum as its value. Events never carry a value, and this adds
 * none: anything else (an object it does not know) is left out rather than stringified.
 */
final readonly class EventSummary
{
    /** @return array<string, scalar|null> */
    public static function of(object $event): array
    {
        $summary = [];

        foreach (get_object_vars($event) as $property => $value) {
            $name = (string) $property;
            match (true) {
                $value instanceof Subject            => $summary += self::subject($value),
                $value instanceof Actor              => $summary['actor'] = self::actor($value),
                $value instanceof BackedEnum         => $summary[$name] = $value->value,
                is_scalar($value) || $value === null => $summary[$name] = $value,
                default                              => null,
            };
        }

        return $summary;
    }

    /** `user:<alias>:<id>`, `system`, `token:<alias>:<id>`… */
    public static function actor(Actor $actor): string
    {
        return $actor->id === null ? $actor->type->value : sprintf('%s:%s:%s', $actor->type->value, $actor->alias ?? '?', $actor->id);
    }

    /** @return array<string, string|null> */
    private static function subject(Subject $subject): array
    {
        return [
            'store'    => $subject->store->value,
            'scope'    => $subject->scope()->value,
            'key'      => $subject->key?->value,
            'locale'   => $subject->locale->value,
            'category' => $subject->category,
        ];
    }
}
