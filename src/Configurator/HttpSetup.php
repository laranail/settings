<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `http.*`, inside `SettingsSetup::http()`. */
final readonly class HttpSetup
{
    public function __construct(private SetupValues $values) {}

    /** `http.enabled`: register the REST API routes. */
    public function enabled(bool $enabled = true): self
    {
        $this->values->set('http.enabled', $enabled);

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->values->set('http.prefix', SetupValues::filled('http()->prefix()', 'http.prefix', trim($prefix, '/')));

        return $this;
    }

    /** `http.middleware`: the middleware the routes run under (none: the package's default). */
    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $name) {
            SetupValues::filled('http()->middleware()', 'http.middleware', $name);
        }

        $this->values->set('http.middleware', array_values($middleware));

        return $this;
    }

    /** `http.rate_limit`: requests per minute per user (0: no limit). */
    public function rateLimit(int $perMinute): self
    {
        $this->values->set('http.rate_limit', SetupValues::atLeast('http()->rateLimit()', 'http.rate_limit', $perMinute, 0));

        return $this;
    }

    public function pageSize(int $size): self
    {
        $this->values->set('http.page_size', SetupValues::atLeast('http()->pageSize()', 'http.page_size', $size, 1));

        return $this;
    }
}
