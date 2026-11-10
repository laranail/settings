<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Events\LayerSkipped;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;

/**
 * The database layer of a store in bootstrap mode (`stores.<name>.bootstrap`). The layer is
 * skipped only while there is positive evidence that the application is being installed AND the
 * database is not ready; a skipped layer reads nothing and accepts no writes, so writes go to
 * the next writable layer (the runtime file). Without evidence it is exactly the database layer:
 * an outage then raises DriverUnavailable, never a silent fallback (G-36).
 *
 * Nothing is checked at construction; the first read or write decides.
 */
final class BootstrapGatedDriver implements DatabaseBacked
{
    private bool $reported = false;

    /** @param ?Dispatcher $events told ({@see LayerSkipped}) the first time the layer named `$layer` is skipped */
    public function __construct(
        private readonly DatabaseDriver $inner,
        private readonly StoreName $store,
        private readonly InstallationEvidence $evidence,
        private readonly ?Dispatcher $events = null,
        private readonly string $layer = 'database',
    ) {}

    public function database(): DatabaseDriver
    {
        return $this->inner;
    }

    public function isActive(): bool
    {
        if (! $this->evidence->holds() || $this->inner->isReady($this->store)) {
            return true;
        }

        if (! $this->reported && $this->events instanceof Dispatcher) {
            $this->reported = true;
            $this->events->dispatch(new LayerSkipped($this->store->value, $this->layer, 'the application is being installed and the database is not ready'));
        }

        return false;
    }

    public function load(StoreName $store, array $scopes): RecordSet
    {
        return $this->isActive() ? $this->inner->load($store, $scopes) : new RecordSet;
    }

    public function put(StoreName $store, Record $record): void
    {
        $this->assertActive($store);
        $this->inner->put($store, $record);
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $this->assertActive($store);
        $this->inner->forget($store, $scope, $key, $locale);
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable() && $this->isActive();
    }

    private function assertActive(StoreName $store): void
    {
        if (! $this->isActive()) {
            throw new StoreNotWritable(sprintf('The database layer of store [%s] is skipped while the application is being installed.', $store));
        }
    }
}
