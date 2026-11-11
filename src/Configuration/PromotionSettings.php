<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configuration;

use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * Fluent setters for one store's promotion, for the current request or job. Each setter wins over
 * the same option in configuration; an option it does not set keeps coming from configuration.
 *
 *     Settings::store('settings')->promotion()->auto()->only('settings')->forOwners('user');
 *
 * It writes to the request's runtime configuration, so nothing outlives the request.
 */
final readonly class PromotionSettings
{
    public function __construct(
        private RuntimeConfiguration $runtime,
        private string $store,
    ) {}

    /** Promote the runtime file once the database is ready. */
    public function auto(): self
    {
        return $this->set(['mode' => 'auto']);
    }

    /** Promote only when asked: `promote()` or the command. */
    public function manual(): self
    {
        return $this->set(['mode' => 'manual']);
    }

    /**
     * Copy only these kinds: `settings` (application-wide values) and `owners` (per-owner values).
     *
     * @throws InvalidConfiguration for a kind the runtime file cannot hold
     */
    public function only(string ...$kinds): self
    {
        return $this->set(['include' => array_values($kinds)]);
    }

    /** Copy every value. */
    public function everything(): self
    {
        return $this->set(['include' => ['*'], 'owners' => null]);
    }

    /** Copy owner values only for these morph aliases. */
    public function forOwners(string ...$aliases): self
    {
        return $this->set(['owners' => array_values($aliases)]);
    }

    /** Copy owner values for every owner alias. */
    public function forAllOwners(): self
    {
        return $this->set(['owners' => null]);
    }

    /** @param array{mode?: string, include?: list<string>, owners?: list<string>|null} $options */
    private function set(array $options): self
    {
        $this->runtime->setPromotion($this->store, $options);

        return $this;
    }
}
