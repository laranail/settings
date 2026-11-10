<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Simtabi\Laranail\Settings\Enums\TranslationsMode;

/**
 * Reads and writes titles, labels and descriptions per `translations.mode`: plain text, or a JSON
 * map of locale to text where `''` applies to any locale.
 */
final readonly class Translations
{
    public function __construct(
        private TranslationsMode $mode,
    ) {}

    public function isJson(): bool
    {
        return $this->mode === TranslationsMode::Json;
    }

    /**
     * The text as the column holds it.
     *
     * @param array<string, string> $translations locale => text, used in json mode
     */
    public function store(string $text, array $translations = []): string
    {
        return $this->isJson() ? Json::encode(['' => $text] + $translations, 'A title or description') : $text;
    }

    /** {@see store()} for a nullable column: null stays null. */
    public function storeNullable(?string $text): ?string
    {
        return $text === null ? null : $this->store($text);
    }

    /** The text for any locale: the `''` entry, else the first text, else the stored value as is. */
    public function text(string $stored): string
    {
        $texts = $this->texts($stored);

        if (isset($texts[''])) {
            return $texts[''];
        }

        foreach ($texts as $text) {
            return $text;
        }

        return $stored;
    }

    /** {@see text()} for a nullable column: null stays null. */
    public function nullableText(?string $stored): ?string
    {
        return $stored === null ? null : $this->text($stored);
    }

    /**
     * Every text by locale; `['' => stored]` when the value is not a JSON map of texts.
     *
     * @return array<string, string>
     */
    public function texts(string $stored): array
    {
        if (! $this->isJson()) {
            return ['' => $stored];
        }

        $decoded = json_decode($stored, true);
        $texts = [];

        foreach (is_array($decoded) ? $decoded : [] as $locale => $text) {
            if (is_string($text)) {
                $texts[(string) $locale] = $text;
            }
        }

        return $texts === [] ? ['' => $stored] : $texts;
    }
}
