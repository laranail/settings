<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\Repository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Response as IlluminateResponse;

/**
 * Replays the stored response to a REST write retried with the same `Idempotency-Key`, user,
 * method and path. Server errors are not stored, so they can be retried.
 */
final readonly class IdempotentWrites
{
    public function __construct(
        private Repository $cache,
        private int $ttl = 86400,
        private string $prefix = 'laranail-settings',
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            /** @var Response */
            return $next($request);
        }

        $user = $request->user()?->getAuthIdentifier();
        $cacheKey = $this->prefix . ':idempotency:' . sha1(implode('|', [is_scalar($user) ? (string) $user : '', $request->method(), $request->path(), $key]));
        $stored = $this->cache->get($cacheKey);

        if (is_array($stored) && is_int($stored['status'] ?? null) && is_string($stored['body'] ?? null)) {
            $headers = is_array($stored['headers'] ?? null) ? $stored['headers'] : [];

            return new IlluminateResponse($stored['body'], $stored['status'], $headers + ['Idempotent-Replayed' => 'true']);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $this->cache->put($cacheKey, [
                'status'  => $response->getStatusCode(),
                'body'    => (string) $response->getContent(),
                'headers' => array_filter([
                    'Content-Type' => $response->headers->get('Content-Type'),
                    'ETag'         => $response->headers->get('ETag'),
                ], static fn (?string $value): bool => $value !== null),
            ], $this->ttl);
        }

        return $response;
    }
}
