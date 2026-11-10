<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

/**
 * Titles, labels and descriptions are stored as JSON maps of locale to text, where `''` is the
 * text for any locale.
 */
final readonly class Translations
{
    /**
     * The map to store for a text and its translations.
     *
     * @param array<string, string> $translations locale => text
     *
     * @return array<string, string>
     */
    public static function of(string $text, array $translations = []): array
    {
        return ['' => $text] + $translations;
    }

    /**
     * {@see of()} for a nullable column: null stays null.
     *
     * @return array<string, string>|null
     */
    public static function ofNullable(?string $text): ?array
    {
        return $text === null ? null : self::of($text);
    }

    /** The text for any locale: the `''` entry, else the first text; null when there is none. */
    public static function text(mixed $stored): ?string
    {
        $texts = self::texts($stored);

        return $texts[''] ?? ($texts === [] ? null : reset($texts));
    }

    /**
     * Every text by locale. A plain string reads as the text for any locale.
     *
     * @return array<string, string>
     */
    public static function texts(mixed $stored): array
    {
        if (is_string($stored)) {
            return ['' => $stored];
        }

        $texts = [];

        foreach (is_array($stored) ? $stored : [] as $locale => $text) {
            if (is_string($text)) {
                $texts[(string) $locale] = $text;
            }
        }

        return $texts;
    }
}
