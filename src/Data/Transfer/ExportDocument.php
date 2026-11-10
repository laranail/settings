<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;

/**
 * A store's settings and categories as portable JSON (G-18, G-42): categories by slug, settings by
 * scope, key and locale, never a numeric id, so a document moves between databases whatever their
 * `ids.type`. Encrypted values follow the {@see ExportMode}. `source` names an importer when the
 * document was read from another package (G-43).
 */
final readonly class ExportDocument
{
    public const string FORMAT = 'laranail/settings';

    public const int VERSION = 1;

    /**
     * @param list<ExportedCategory> $categories
     * @param list<ExportedSetting> $settings
     */
    public function __construct(
        public string $store,
        public array $categories = [],
        public array $settings = [],
        public ExportMode $mode = ExportMode::Redacted,
        public ?string $exportedAt = null,
        public ?string $source = null,
    ) {}

    /**
     * Read a document; anything malformed raises InvalidImport naming the entry.
     *
     * @throws InvalidImport
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidImport('The export file is not a JSON object.');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidImport
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidImport(sprintf('The export file is not a %s export (format: %s).', self::FORMAT, is_scalar($data['format'] ?? null) ? (string) $data['format'] : 'missing'));
        }

        if (! is_int($data['version'] ?? null) || $data['version'] > self::VERSION) {
            throw new InvalidImport(sprintf('The export file has version [%s]; this release reads version %d.', is_scalar($data['version'] ?? null) ? (string) $data['version'] : '?', self::VERSION));
        }

        $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

        return new self(
            is_string($data['store'] ?? null) ? $data['store'] : 'settings',
            array_values(array_map(
                static fn (mixed $entry, int|string $index): ExportedCategory => ExportedCategory::fromArray(is_array($entry) ? $entry : [], $index),
                $categories,
                array_keys($categories),
            )),
            array_values(array_map(
                static fn (mixed $entry, int|string $index): ExportedSetting => ExportedSetting::fromArray(is_array($entry) ? $entry : [], $index),
                $settings,
                array_keys($settings),
            )),
            ExportMode::tryFrom(is_string($data['mode'] ?? null) ? $data['mode'] : '') ?? ExportMode::Redacted,
            is_string($data['exported_at'] ?? null) ? $data['exported_at'] : null,
            is_string($data['source'] ?? null) ? $data['source'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'store'       => $this->store,
            'mode'        => $this->mode->value,
            'exported_at' => $this->exportedAt,
            'source'      => $this->source,
            'categories'  => array_map(static fn (ExportedCategory $category): array => $category->toArray(), $this->categories),
            'settings'    => array_map(static fn (ExportedSetting $setting): array => $setting->toArray(), $this->settings),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string, ExportedSetting> by identity */
    public function settingsByIdentity(): array
    {
        $indexed = [];

        foreach ($this->settings as $setting) {
            $indexed[$setting->identity()] = $setting;
        }

        return $indexed;
    }
}
