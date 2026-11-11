<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Middleware;

use Closure;
use JsonException;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Cache\Repository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Response as IlluminateResponse;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Replays the stored response to a REST write retried with the same `Idempotency-Key`, actor,
 * host, method and path. A retry with a different body is refused with 422. Server errors are
 * not stored, so they can be retried, and nothing is stored for a request with no actor.
 *
 * The stored response is encrypted, because it can hold the value of an encrypted setting.
 */
final readonly class IdempotentWrites
{
    public function __construct(
        private Repository $cache,
        private AccessGuard $access,
        private LaravelValueEncrypter $encrypter,
        private int $ttl = 86400,
        private string $prefix = 'laranail-settings',
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $actor = is_string($key) && $key !== '' && strlen($key) <= 255 ? $this->access->resolveActor($request) : null;

        if (! is_string($key) || ! $actor instanceof Actor) {
            /** @var Response */
            return $next($request);
        }

        $cacheKey = $this->prefix . ':idempotency:' . hash('sha256', implode('|', [
            $actor->type->value, $actor->alias ?? '', $actor->id ?? '', $request->getHost(), $request->method(), $request->path(), $key,
        ]));
        $fingerprint = hash('sha256', $request->getQueryString() . '|' . $request->getContent());
        $stored = $this->read($this->cache->get($cacheKey));

        if ($stored !== null) {
            if ($stored['fingerprint'] !== $fingerprint) {
                throw new UnprocessableEntityHttpException('This Idempotency-Key was already used with a different request; send a new key.');
            }

            return new IlluminateResponse($stored['body'], $stored['status'], $stored['headers'] + ['Idempotent-Replayed' => 'true']);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $this->store($cacheKey, $fingerprint, $response);
        }

        return $response;
    }

    private function store(string $cacheKey, string $fingerprint, Response $response): void
    {
        $entry = json_encode([
            'fingerprint' => $fingerprint,
            'status'      => $response->getStatusCode(),
            'body'        => (string) $response->getContent(),
            'headers'     => array_filter([
                'Content-Type' => $response->headers->get('Content-Type'),
                'ETag'         => $response->headers->get('ETag'),
            ], static fn (?string $value): bool => $value !== null),
        ], JSON_THROW_ON_ERROR);

        try {
            $this->cache->put($cacheKey, $this->encrypter->encrypt($entry), $this->ttl);
        } catch (SettingsException) {
            // No application key: the write is answered, but a retry runs it again.
        }
    }

    /** @return array{fingerprint: string, status: int, body: string, headers: array<string, string>}|null */
    private function read(mixed $stored): ?array
    {
        if (! is_string($stored)) {
            return null;
        }

        try {
            $entry = json_decode($this->encrypter->decrypt($stored), true, flags: JSON_THROW_ON_ERROR);
        } catch (SettingsException|JsonException) {
            return null;
        }

        if (! is_array($entry) || ! is_string($entry['fingerprint'] ?? null) || ! is_int($entry['status'] ?? null) || ! is_string($entry['body'] ?? null)) {
            return null;
        }

        $headers = is_array($entry['headers'] ?? null) ? array_filter($entry['headers'], is_string(...)) : [];

        /** @var array<string, string> $headers */
        return ['fingerprint' => $entry['fingerprint'], 'status' => $entry['status'], 'body' => $entry['body'], 'headers' => $headers];
    }
}
