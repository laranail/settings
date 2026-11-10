<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/** One setting in an export; an encrypted value is redacted, ciphertext or plaintext by export mode. */
final readonly class ExportedSetting
{
    public function __construct(
        public Scope $scope,
        public Key $key,
        public Locale $locale,
        public mixed $value = null,
        public ?string $ciphertext = null,
        public bool $redacted = false,
        public ?string $type = null,
        public bool $encrypted = false,
        public bool $locked = false,
        public bool $hasValue = true,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidImport
     */
    public static function fromArray(array $data, int|string $index): self
    {
        try {
            $scope = Scope::fromString(is_string($data['scope'] ?? null) ? $data['scope'] : '') ?? throw new InvalidImport('bad scope');

            return new self(
                $scope,
                new Key(is_string($data['key'] ?? null) ? $data['key'] : ''),
                new Locale(is_string($data['locale'] ?? null) ? $data['locale'] : ''),
                $data['value'] ?? null,
                is_string($data['ciphertext'] ?? null) ? $data['ciphertext'] : null,
                ($data['redacted'] ?? false) === true,
                is_string($data['type'] ?? null) ? $data['type'] : null,
                ($data['encrypted'] ?? false) === true,
                ($data['locked'] ?? false) === true,
                array_key_exists('value', $data) || is_string($data['ciphertext'] ?? null) || ($data['redacted'] ?? false) === true,
            );
        } catch (SettingsException $exception) {
            throw new InvalidImport(sprintf('settings.%s: %s', $index, $exception->getMessage()), 0, $exception);
        }
    }

    /** `scope|key|locale`: how two documents' settings are matched. */
    public function identity(): string
    {
        return $this->scope->value . '|' . $this->key->value . '|' . $this->locale->value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entry = ['scope' => $this->scope->value, 'key' => $this->key->value, 'locale' => $this->locale->value];

        if ($this->redacted) {
            $entry['redacted'] = true;
        } elseif ($this->ciphertext !== null) {
            $entry['ciphertext'] = $this->ciphertext;
        } elseif ($this->hasValue) {
            $entry['value'] = $this->value;
        }

        return $entry + array_filter([
            'type'      => $this->type,
            'encrypted' => $this->encrypted ?: null,
            'locked'    => $this->locked ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
