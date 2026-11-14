<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Values;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Support\LaravelValueValidator;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
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
        #[Config('laranail.settings.values.max_bytes', 1048576)]
        private mixed $maxBytes = 1048576,
    ) {}

    /** The encoded, and where defined encrypted, payload; refused when larger than `values.max_bytes`. */
    public function toPayload(Key $key, mixed $value, ?Definition $definition): string
    {
        $payload = $this->encoded($key, $value, $definition);

        return $definition instanceof Definition && $definition->encrypted ? $this->encrypter->encrypt($payload) : $payload;
    }

    /**
     * The value a read returns after writing this one, without storing or encrypting it: validated,
     * cast, encoded, held to `values.max_bytes` and read back, as a runtime override holds it.
     */
    public function inMemory(Key $key, mixed $value, ?Definition $definition): mixed
    {
        $decoded = $this->encoder->decode($key, $this->encoded($key, $value, $definition));
        $type = $definition?->type;

        return $type === null ? $decoded : $this->casters->get($type)->fromStorage($decoded);
    }

    public function fromRecord(Record $record, ?Definition $definition): mixed
    {
        $payload = (string) $record->payload;
        $decoded = $this->encoder->decode($record->key, $record->encrypted ? $this->encrypter->decrypt($payload) : $payload);
        $type = $record->type ?? $definition?->type;

        return $type === null ? $decoded : $this->casters->get($type)->fromStorage($decoded);
    }

    /** Validate raw, cast and encode; refused when larger than `values.max_bytes`. */
    private function encoded(Key $key, mixed $value, ?Definition $definition): string
    {
        $this->validator->validate($key, $value, $definition->rules ?? []);

        $type = $definition?->type;
        $stored = $type === null ? $value : $this->casters->get($type)->toStorage($key, $value);
        $payload = $this->encoder->encode($key, $stored);
        $limit = is_numeric($this->maxBytes) ? (int) $this->maxBytes : null;

        if ($limit !== null && strlen($payload) > $limit) {
            throw new InvalidSettingValue(sprintf('Setting [%s]: the value is %d bytes, over the %d-byte limit in laranail.settings.values.max_bytes.', $key, strlen($payload), $limit));
        }

        return $payload;
    }
}
