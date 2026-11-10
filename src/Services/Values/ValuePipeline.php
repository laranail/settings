<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Values;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Container\Attributes\Singleton;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Support\LaravelValueValidator;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;

/**
 * Turns a caller's value into a stored payload (validate raw → cast → encode → encrypt) and a
 * stored record back into a value (decrypt → decode → cast).
 */
#[Singleton]
final readonly class ValuePipeline
{
    public function __construct(
        private CasterRegistry $casters,
        private LaravelValueValidator $validator,
        private LaravelValueEncrypter $encrypter,
        private ValueEncoder $encoder,
    ) {}

    /** The encoded (and, for an encrypted definition, encrypted) payload. */
    public function toPayload(Key $key, mixed $value, ?Definition $definition): string
    {
        $this->validator->validate($key, $value, $definition->rules ?? []);

        $type = $definition?->type;
        $stored = $type === null ? $value : $this->casters->get($type)->toStorage($key, $value);
        $payload = $this->encoder->encode($key, $stored);

        return $definition instanceof Definition && $definition->encrypted ? $this->encrypter->encrypt($payload) : $payload;
    }

    public function fromRecord(Record $record, ?Definition $definition): mixed
    {
        $payload = (string) $record->payload;
        $decoded = $this->encoder->decode($record->key, $record->encrypted ? $this->encrypter->decrypt($payload) : $payload);
        $type = $record->type ?? $definition?->type;

        return $type === null ? $decoded : $this->casters->get($type)->fromStorage($decoded);
    }
}
