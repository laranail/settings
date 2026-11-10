<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Http;

/**
 * A validated request body and the route parameters beside it. Accessors type each field, so a
 * controller never reads an array by hand.
 */
final readonly class BodyInput
{
    /**
     * @param array<string, string> $params route parameters
     * @param array<string, mixed> $body validated body
     * @param bool $hasParent whether the body named `parent` at all (null moves to the top level)
     */
    public function __construct(
        public array $params,
        public array $body,
        public bool $hasParent = false,
    ) {}

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function text(string $name): ?string
    {
        $value = $this->body[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function integer(string $name, int $default = 0): int
    {
        $value = $this->body[$name] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $name): bool
    {
        return filter_var($this->body[$name] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** @return array<array-key, mixed> */
    public function array(string $name): array
    {
        $value = $this->body[$name] ?? [];

        return is_array($value) ? $value : [];
    }

    public function value(string $name): mixed
    {
        return $this->body[$name] ?? null;
    }
}
