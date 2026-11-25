<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Override;
use Simtabi\Laranail\Settings\Approvals\Approvals;
use Simtabi\Laranail\Settings\Audit\AuditQuery;
use Simtabi\Laranail\Settings\Categories\CategoryManager;
use Simtabi\Laranail\Settings\Configuration\PersistedConfigurationEditor;
use Simtabi\Laranail\Settings\Configuration\PromotionSettings;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Data\HistoryDiff;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\History\HistoryQuery;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * The base for a decorator a configurator registers with `decorate()`. It forwards every call to
 * the settings it wraps, and wraps again each handle a call returns (`store()`, `for()`,
 * `locale()`, `as()`, `asSystem()`, `asCurrentActor()` and `because()` each return a new handle),
 * so the decorator survives `Settings::store('teams')->for($team)->as($user)`. Macros reach the
 * wrapped settings through `__call()`. Override the methods you change; call the parent to forward.
 */
abstract class SettingsDecorator implements Settings
{
    public function __construct(protected Settings $inner) {}

    /** @param array<array-key, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->inner->{$method}(...$arguments);

        return $result instanceof Settings ? $this->wrap($result) : $result;
    }

    /** The settings this decorator wraps. */
    public function inner(): Settings
    {
        return $this->inner;
    }

    #[Override]
    public function store(string $name): static
    {
        return $this->wrap($this->inner->store($name));
    }

    #[Override]
    public function for(Settable|OwnerRef $owner): static
    {
        return $this->wrap($this->inner->for($owner));
    }

    #[Override]
    public function locale(string $locale): static
    {
        return $this->wrap($this->inner->locale($locale));
    }

    #[Override]
    public function as(Authenticatable|Actor $actor): static
    {
        return $this->wrap($this->inner->as($actor));
    }

    #[Override]
    public function asSystem(?string $reason = null): static
    {
        return $this->wrap($this->inner->asSystem($reason));
    }

    #[Override]
    public function asCurrentActor(?Request $request = null): static
    {
        return $this->wrap($this->inner->asCurrentActor($request));
    }

    #[Override]
    public function because(string $reason): static
    {
        return $this->wrap($this->inner->because($reason));
    }

    #[Override]
    public function actor(): ?Actor
    {
        return $this->inner->actor();
    }

    #[Override]
    public function configuration(): RuntimeConfiguration
    {
        return $this->inner->configuration();
    }

    #[Override]
    public function withConfiguration(callable $change, callable $run): mixed
    {
        return $this->inner->withConfiguration($change, $run);
    }

    #[Override]
    public function override(string|array $key, mixed $value = null): void
    {
        $this->inner->override($key, $value);
    }

    #[Override]
    public function forgetOverride(string $key): void
    {
        $this->inner->forgetOverride($key);
    }

    #[Override]
    public function hasOverride(string $key): bool
    {
        return $this->inner->hasOverride($key);
    }

    #[Override]
    public function clearOverrides(): void
    {
        $this->inner->clearOverrides();
    }

    #[Override]
    public function withOverrides(array $values, callable $run): mixed
    {
        return $this->inner->withOverrides($values, $run);
    }

    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    #[Override]
    public function lookup(string $key): Lookup
    {
        return $this->inner->lookup($key);
    }

    #[Override]
    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    #[Override]
    public function missing(string $key): bool
    {
        return $this->inner->missing($key);
    }

    #[Override]
    public function many(array $keys): array
    {
        return $this->inner->many($keys);
    }

    #[Override]
    public function all(?string $prefix = null): array
    {
        return $this->inner->all($prefix);
    }

    #[Override]
    public function definitions(): array
    {
        return $this->inner->definitions();
    }

    #[Override]
    public function string(string $key): string
    {
        return $this->inner->string($key);
    }

    #[Override]
    public function integer(string $key): int
    {
        return $this->inner->integer($key);
    }

    #[Override]
    public function float(string $key): float
    {
        return $this->inner->float($key);
    }

    #[Override]
    public function boolean(string $key): bool
    {
        return $this->inner->boolean($key);
    }

    #[Override]
    public function array(string $key): array
    {
        return $this->inner->array($key);
    }

    #[Override]
    public function explain(string $key): Explanation
    {
        return $this->inner->explain($key);
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);
    }

    #[Override]
    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }

    #[Override]
    public function setMany(array $values): void
    {
        $this->inner->setMany($values);
    }

    #[Override]
    public function forgetMany(iterable $keys): void
    {
        $this->inner->forgetMany($keys);
    }

    #[Override]
    public function remember(string $key, callable $value): mixed
    {
        return $this->inner->remember($key, $value);
    }

    #[Override]
    public function lock(string $key): void
    {
        $this->inner->lock($key);
    }

    #[Override]
    public function unlock(string $key): void
    {
        $this->inner->unlock($key);
    }

    #[Override]
    public function flush(): void
    {
        $this->inner->flush();
    }

    #[Override]
    public function clearCache(): void
    {
        $this->inner->clearCache();
    }

    #[Override]
    public function warmCache(): int
    {
        return $this->inner->warmCache();
    }

    #[Override]
    public function preload(iterable $owners): void
    {
        $this->inner->preload($owners);
    }

    #[Override]
    public function categories(): CategoryManager
    {
        return $this->inner->categories();
    }

    #[Override]
    public function promote(?PromotionRequest $request = null): PromotionReport
    {
        return $this->inner->promote($request);
    }

    #[Override]
    public function promoteWhenReady(?PromotionSelection $only = null): ?PromotionReport
    {
        return $this->inner->promoteWhenReady($only);
    }

    #[Override]
    public function promotion(): PromotionSettings
    {
        return $this->inner->promotion();
    }

    #[Override]
    public function audit(): AuditQuery
    {
        return $this->inner->audit();
    }

    #[Override]
    public function history(): HistoryQuery
    {
        return $this->inner->history();
    }

    #[Override]
    public function rollback(string $key, int $toVersion): int
    {
        return $this->inner->rollback($key, $toVersion);
    }

    #[Override]
    public function propose(string $key, mixed $value): ChangeRequest
    {
        return $this->inner->propose($key, $value);
    }

    #[Override]
    public function approvals(): Approvals
    {
        return $this->inner->approvals();
    }

    #[Override]
    public function export(ExportMode $mode = ExportMode::Redacted): ExportDocument
    {
        return $this->inner->export($mode);
    }

    #[Override]
    public function import(ExportDocument $document): ImportReport
    {
        return $this->inner->import($document);
    }

    #[Override]
    public function previewImport(ExportDocument $document): ImportReport
    {
        return $this->inner->previewImport($document);
    }

    #[Override]
    public function compare(ExportDocument $document): StoreDiff
    {
        return $this->inner->compare($document);
    }

    #[Override]
    public function persistedConfiguration(): PersistedConfigurationEditor
    {
        return $this->inner->persistedConfiguration();
    }

    #[Override]
    public function historyDiff(string $key, int $from, int $to): HistoryDiff
    {
        return $this->inner->historyDiff($key, $from, $to);
    }

    /** This decorator around another handle, so a derived handle keeps it. */
    protected function wrap(Settings $handle): static
    {
        $copy = clone $this;
        $copy->inner = $handle;

        return $copy;
    }
}
