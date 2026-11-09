<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Testing;

use PHPUnit\Framework\Assert;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Resolution\Explanation;

/**
 * Installed by `Settings::fake()`: the real engine over in-memory layers, recording writes.
 *
 * It replaces the container binding, so it must be installed before the code under test
 * resolves `Settings`; anything resolved earlier keeps the real instance.
 */
final readonly class SettingsFake implements Settings
{
    /** @param FakeLog $log shared by every handle derived from this fake */
    public function __construct(
        private Settings $inner,
        private FakeLog $log = new FakeLog,
    ) {}

    public function store(string $name): static
    {
        return new self($this->inner->store($name), $this->log);
    }

    public function for(Settable|OwnerRef $owner): static
    {
        return new self($this->inner->for($owner), $this->log);
    }

    public function locale(string $locale): static
    {
        return new self($this->inner->locale($locale), $this->log);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function lookup(string $key): Lookup
    {
        return $this->inner->lookup($key);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function string(string $key): string
    {
        return $this->inner->string($key);
    }

    public function integer(string $key): int
    {
        return $this->inner->integer($key);
    }

    public function float(string $key): float
    {
        return $this->inner->float($key);
    }

    public function boolean(string $key): bool
    {
        return $this->inner->boolean($key);
    }

    public function array(string $key): array
    {
        return $this->inner->array($key);
    }

    public function explain(string $key): Explanation
    {
        return $this->inner->explain($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);
        $this->log->saved[] = [$key, $value];
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
        $this->log->forgotten[] = $key;
    }

    public function lock(string $key): void
    {
        $this->inner->lock($key);
    }

    public function unlock(string $key): void
    {
        $this->inner->unlock($key);
    }

    /** Assert the key was saved, and with exactly this value when one is given. */
    public function assertSaved(string $key, mixed ...$value): void
    {
        $checksValue = $value !== [];
        $expected = $value[0] ?? null;
        $matches = array_filter(
            $this->log->saved,
            static fn (array $write): bool => $write[0] === $key && (! $checksValue || $write[1] === $expected),
        );

        Assert::assertNotEmpty($matches, sprintf('Setting [%s] was not saved%s.', $key, $checksValue ? ' with the expected value' : ''));
    }

    public function assertNotSaved(string $key): void
    {
        $matches = array_filter($this->log->saved, static fn (array $write): bool => $write[0] === $key);

        Assert::assertEmpty($matches, sprintf('Setting [%s] was saved unexpectedly.', $key));
    }

    public function assertForgotten(string $key): void
    {
        Assert::assertContains($key, $this->log->forgotten, sprintf('Setting [%s] was not forgotten.', $key));
    }
}
