<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Support;

/**
 * A deterministic pairwise (all-pairs) covering set over named factors: every value of every
 * factor meets every value of every other factor in at least one row. Greedy over the full
 * product, which is small enough here (576 rows) to enumerate: each step takes the first row
 * that covers the most pairs still uncovered.
 */
final class Pairwise
{
    /**
     * @param array<string, list<string|bool>> $factors
     *
     * @return list<array<string, string|bool>>
     */
    public static function rows(array $factors): array
    {
        $product = self::product($factors);
        $uncovered = [];

        foreach ($product as $row) {
            foreach (self::pairs($row) as $pair) {
                $uncovered[$pair] = true;
            }
        }

        $rows = [];

        while ($uncovered !== []) {
            $best = null;
            $bestCount = 0;

            foreach ($product as $row) {
                $count = count(array_intersect_key(array_flip(self::pairs($row)), $uncovered));

                if ($count > $bestCount) {
                    [$best, $bestCount] = [$row, $count];
                }
            }

            if ($best === null) {
                break;
            }

            $rows[] = $best;

            foreach (self::pairs($best) as $pair) {
                unset($uncovered[$pair]);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, list<string|bool>> $factors
     *
     * @return list<array<string, string|bool>>
     */
    public static function product(array $factors): array
    {
        $rows = [[]];

        foreach ($factors as $name => $values) {
            $next = [];

            foreach ($rows as $row) {
                foreach ($values as $value) {
                    $next[] = $row + [$name => $value];
                }
            }

            $rows = $next;
        }

        return $rows;
    }

    /**
     * Every pair of (factor = value) in one row, as stable strings.
     *
     * @param array<string, string|bool> $row
     *
     * @return list<string>
     */
    public static function pairs(array $row): array
    {
        $cells = [];

        foreach ($row as $name => $value) {
            $cells[] = $name . '=' . var_export($value, true);
        }

        $pairs = [];

        foreach ($cells as $i => $a) {
            foreach (array_slice($cells, $i + 1) as $b) {
                $pairs[] = $a . '&' . $b;
            }
        }

        return $pairs;
    }
}
