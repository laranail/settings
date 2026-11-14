<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Middleware;

use Closure;
use JsonException;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Response as IlluminateResponse;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Replays the stored response to a REST write retried with the same `Idempotency-Key`, tenant,
 * actor, host, method and path. A retry with a different body is refused with 422, and one sent while
 * the first is still running with 409. Server errors are not stored, so they can be retried, and
 * nothing is stored for a request with no actor.
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
        private ?TenantDiscriminator $tenant = null,
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
            // The tenant too: `user:1` in two tenants on one host is two people.
            $this->tenant?->current() ?? '', $actor->type->value, $actor->alias ?? '', $actor->id ?? '', $request->getHost(), $request->method(), $request->path(), $key,
        ]));
        $fingerprint = hash('sha256', $request->getQueryString() . '|' . $request->getContent());
        $replay = $this->replay($cacheKey, $fingerprint);

        if ($replay instanceof Response) {
            return $replay;
        }

        // One request per key at a time: a retry sent while the first runs would write twice.
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock($cacheKey . ':lock', 60) : null;

        if ($lock !== null && ! $lock->get()) {
            throw new ConflictHttpException('A request with this Idempotency-Key is still being processed; retry once it has answered.');
        }

        try {
            // The first request may have finished between the read above and taking the lock.
            $replay = $this->replay($cacheKey, $fingerprint);

            if ($replay instanceof Response) {
                return $replay;
            }

            /** @var Response $response */
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $this->store($cacheKey, $fingerprint, $response);
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /** The stored response for this key, or null; a different body under the same key is 422. */
    private function replay(string $cacheKey, string $fingerprint): ?Response
    {
        $stored = $this->read($this->cache->get($cacheKey));

        if ($stored === null) {
            return null;
        }

        if ($stored['fingerprint'] !== $fingerprint) {
            throw new UnprocessableEntityHttpException('This Idempotency-Key was already used with a different request; send a new key.');
        }

        return new IlluminateResponse($stored['body'], $stored['status'], $stored['headers'] + ['Idempotent-Replayed' => 'true']);
    }

    private function store(string $cacheKey, string $fingerprint, Response $response): void
    {
        // A streamed body (an export) cannot be kept, and a read needs no replay.
        $body = $response->getContent();

        if ($body === false) {
            return;
        }

        $entry = json_encode([
            'fingerprint' => $fingerprint,
            'status'      => $response->getStatusCode(),
            'body'        => $body,
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
