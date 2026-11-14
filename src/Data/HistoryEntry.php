<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Enums\HistoryOperation;

/**
 * One version of one setting. The payloads are stored exactly as the values table held them, still
 * encrypted where flagged; a null payload means no value.
 */
final readonly class HistoryEntry
{
    public function __construct(
        public string $id,
        public string $store,
        public ?string $settingRef,
        public string $scope,
        public string $key,
        public string $locale,
        public HistoryOperation $operation,
        public ?string $oldValue,
        public ?string $newValue,
        public ?string $oldType,
        public ?string $newType,
        public bool $oldEncrypted,
        public bool $newEncrypted,
        public int $version,
        public ActorType $causerType,
        public ?string $causerAlias,
        public ?string $causerId,
        public ?string $reason,
        public CarbonImmutable $createdAt,
    ) {}

    /** A copy without its payloads, for an actor who may read the history but not the values. */
    public function redacted(): self
    {
        return new self($this->id, $this->store, $this->settingRef, $this->scope, $this->key, $this->locale, $this->operation, null, null, $this->oldType, $this->newType, $this->oldEncrypted, $this->newEncrypted, $this->version, $this->causerType, $this->causerAlias, $this->causerId, $this->reason, $this->createdAt);
    }

    /**
     * The entry with encrypted payloads left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'store'         => $this->store,
            'setting_ref'   => $this->settingRef,
            'scope'         => $this->scope,
            'key'           => $this->key,
            'locale'        => $this->locale,
            'operation'     => $this->operation->value,
            'old_value'     => $this->oldEncrypted ? null : $this->oldValue,
            'new_value'     => $this->newEncrypted ? null : $this->newValue,
            'old_type'      => $this->oldType,
            'new_type'      => $this->newType,
            'old_encrypted' => $this->oldEncrypted,
            'new_encrypted' => $this->newEncrypted,
            'version'       => $this->version,
            'causer_type'   => $this->causerType->value,
            'causer_alias'  => $this->causerAlias,
            'causer_id'     => $this->causerId,
            'reason'        => $this->reason,
            'created_at'    => $this->createdAt->toIso8601String(),
        ];
    }
}
