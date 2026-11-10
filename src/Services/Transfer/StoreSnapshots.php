<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Transfer;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Data\Transfer\ExportedSetting;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Data\Transfer\ExportedCategory;
use Simtabi\Laranail\Settings\Services\Database\CategoryRepository;

/**
 * Exports a store's current-tenant rows to an {@see ExportDocument} and compares a store with one.
 */
final readonly class StoreSnapshots
{
    public function __construct(
        private LaravelValueEncrypter $encrypter,
        private ValueEncoder $encoder,
    ) {}

    /** Whether two exported settings hold the same value and lock. */
    public static function same(ExportedSetting $a, ExportedSetting $b): bool
    {
        if ($a->ciphertext !== null || $b->ciphertext !== null) {
            return $a->ciphertext === $b->ciphertext && $a->locked === $b->locked;
        }

        return $a->hasValue === $b->hasValue
            && json_encode($a->value, JSON_PRESERVE_ZERO_FRACTION) === json_encode($b->value, JSON_PRESERVE_ZERO_FRACTION)
            && $a->locked === $b->locked;
    }

    /** @throws StoreNotWritable when the store has no active database layer */
    public function export(Store $store, ExportMode $mode): ExportDocument
    {
        $database = $store->requireDatabase('; exports read the database layer.');
        $records = $database->load($store->name, array_values(array_filter(array_map(Scope::fromString(...), $database->scopes($store->name)))));

        $settings = [];

        foreach ($records->all() as $record) {
            $settings[] = $this->setting($record, $mode);
        }

        usort($settings, static fn (ExportedSetting $a, ExportedSetting $b): int => strcmp($a->identity(), $b->identity()));

        return new ExportDocument(
            $store->name->value,
            array_map(ExportedCategory::of(...), CategoryRepository::of($store)->all()),
            $settings,
            $mode,
            CarbonImmutable::now()->toIso8601String(),
        );
    }

    /**
     * Compare the store with a document.
     *
     * Encrypted values compare by ciphertext, so a re-encryption reads as a change.
     */
    public function diff(Store $store, ExportDocument $document): StoreDiff
    {
        $current = $this->export($store, $document->mode === ExportMode::Plaintext ? ExportMode::Plaintext : ExportMode::Ciphertext)->settingsByIdentity();
        $incoming = $document->settingsByIdentity();
        $diff = ['added' => [], 'removed' => [], 'changed' => [], 'unchanged' => [], 'unknown' => []];

        foreach ($incoming as $identity => $setting) {
            $existing = $current[$identity] ?? null;

            $bucket = match (true) {
                ! $existing instanceof ExportedSetting => 'added',
                $setting->redacted                     => 'unknown',
                default                                => self::same($existing, $setting) ? 'unchanged' : 'changed',
            };
            $diff[$bucket][] = $identity;
        }

        foreach (array_keys($current) as $identity) {
            if (! isset($incoming[$identity])) {
                $diff['removed'][] = $identity;
            }
        }

        return new StoreDiff(...$diff);
    }

    private function setting(Record $record, ExportMode $mode): ExportedSetting
    {
        $make = static fn (mixed $value = null, ?string $ciphertext = null, bool $redacted = false, bool $hasValue = true): ExportedSetting => new ExportedSetting(
            $record->scope,
            $record->key,
            $record->locale,
            $value,
            $ciphertext,
            $redacted,
            $record->type,
            $record->encrypted,
            $record->locked,
            $hasValue,
        );

        if ($record->payload === null) {
            return $make(hasValue: false);
        }

        if (! $record->encrypted) {
            return $make($this->encoder->decode($record->key, $record->payload));
        }

        return match ($mode) {
            ExportMode::Redacted   => $make(redacted: true),
            ExportMode::Ciphertext => $make(ciphertext: $record->payload),
            ExportMode::Plaintext  => $make($this->encoder->decode($record->key, $this->encrypter->decrypt($record->payload))),
        };
    }
}
