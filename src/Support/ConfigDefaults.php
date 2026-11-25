<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

/**
 * Fills the keys an application's config leaves out of a section from the package defaults.
 *
 * Laravel's `mergeConfigFrom()` merges one level deep, so a published file holding only
 * `'tables' => ['prefix' => 'acme_']` drops every other table name. This merge recurses into
 * fixed-shape sections only: a list, and a map whose keys the application names (its stores,
 * layers, importers and seeded categories), stay exactly as the application wrote them, so a
 * default store or layer it left out does not come back.
 */
final readonly class ConfigDefaults
{
    /** Maps keyed by names the application chooses, as dot paths under `laranail.settings`. */
    public const array NAMED_MAPS = ['stores', 'layers', 'importers', 'seed.categories', 'authentication.guards'];

    /**
     * @param array<array-key, mixed> $defaults the shipped config file
     * @param array<array-key, mixed> $application the configuration after Laravel's merge
     *
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $application, string $path = ''): array
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $application)) {
                $application[$key] = $default;

                continue;
            }

            $name = $path === '' ? (string) $key : $path . '.' . $key;
            $value = $application[$key];

            if (self::isMap($default) && self::isMap($value) && ! in_array($name, self::NAMED_MAPS, true)) {
                $application[$key] = self::merge($default, $value, $name);
            }
        }

        return $application;
    }

    /** @phpstan-assert-if-true array<array-key, mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
