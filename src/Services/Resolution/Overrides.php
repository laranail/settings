<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Resolution;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Illuminate\Container\Attributes\Scoped;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;

/**
 * Values that win over every stored layer for the current request or job, held in memory only.
 *
 * Keyed like a stored value (store, scope, key, locale) and partitioned by tenant. Bound scoped,
 * so Octane and the queue worker drop every override when the request or job ends. Nothing here
 * is written, cached, audited or versioned.
 */
#[Scoped]
final class Overrides
{
    /** The layer name a read answered by an override reports. */
    public const string LAYER = 'override';

    /** @var array<string, array<string, array<string, array<string, mixed>>>> tenant|store => scope => key => locale => value */
    private array $values = [];

    public function __construct(private readonly ?TenantDiscriminator $tenant = null) {}

    public function put(StoreName $store, Scope $scope, Key $key, Locale $locale, mixed $value): void
    {
        $this->values[$this->slot($store)][$scope->value][$key->value][$locale->value] = $value;
    }

    public function has(StoreName $store, Scope $scope, Key $key, Locale $locale): bool
    {
        return array_key_exists($locale->value, $this->values[$this->slot($store)][$scope->value][$key->value] ?? []);
    }

    /** The overriding value; check {@see has()} first, since null is a value. */
    public function get(StoreName $store, Scope $scope, Key $key, Locale $locale): mixed
    {
        return $this->values[$this->slot($store)][$scope->value][$key->value][$locale->value] ?? null;
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        unset($this->values[$this->slot($store)][$scope->value][$key->value][$locale->value]);
    }

    /** @return list<string> the keys overridden in one store and scope, in any locale */
    public function keys(StoreName $store, Scope $scope): array
    {
        $keys = array_keys(array_filter($this->values[$this->slot($store)][$scope->value] ?? [], static fn (array $locales): bool => $locales !== []));

        return array_map(strval(...), $keys);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** Drop every override, for every store, scope and tenant. */
    public function clear(): void
    {
        $this->values = [];
    }

    /**
     * Every override, to hand back to {@see restore()}.
     *
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     */
    public function snapshot(): array
    {
        return $this->values;
    }

    /** @param array<string, array<string, array<string, array<string, mixed>>>> $snapshot from {@see snapshot()} */
    public function restore(array $snapshot): void
    {
        $this->values = $snapshot;
    }

    /** One slot per tenant and store; `|` cannot occur in a store name. */
    private function slot(StoreName $store): string
    {
        return ($this->tenant?->current() ?? '') . '|' . $store->value;
    }
}
