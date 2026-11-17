<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `values.*` and `writes.*`, inside `SettingsSetup::values()`. */
final readonly class ValuesSetup
{
    public function __construct(private SetupValues $values) {}

    /** `values.max_bytes`: the largest encoded value accepted. */
    public function maxBytes(int $bytes): self
    {
        $this->values->set('values.max_bytes', SetupValues::atLeast('values()->maxBytes()', 'values.max_bytes', $bytes, 1));

        return $this;
    }

    /** `writes.retry_on_conflict`: retry a write once when another write changed the bag first. */
    public function retryOnConflict(bool $retry = true): self
    {
        $this->values->set('writes.retry_on_conflict', $retry);

        return $this;
    }
}
