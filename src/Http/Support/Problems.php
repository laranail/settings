<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Support;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Renders package exceptions as RFC 9457 problem details. Other exceptions are left to the
 * application's handler.
 */
final readonly class Problems
{
    /** Problem types are sections of the exceptions reference: this URL plus the type as its fragment. */
    public const string TYPE_BASE = 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#';

    private const string LINES = 'laranail/settings::settings.';

    public function __construct(
        private Translator $translator,
    ) {}

    /** The documented URI of a problem type; every `http-<status>` type shares the `http-status` section. */
    public static function typeUri(string $type): string
    {
        return self::TYPE_BASE . (preg_match('/^http-\d{3}$/', $type) === 1 ? 'http-status' : $type);
    }

    /** The problem response for an exception raised by an API route, or null to leave it alone. */
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'getName') || ! str_starts_with((string) $route->getName(), 'laranail-settings.api.')) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException       => $this->make(422, 'validation', $this->line('problems.validation'), $request, ['errors' => $exception->errors()]),
            $exception instanceof AuthenticationException   => $this->make(401, 'unauthenticated', $this->line('problems.unauthenticated'), $request),
            $exception instanceof ThrottleRequestsException => $this->make(429, 'too-many-requests', $this->line('problems.too-many-requests'), $request),
            $exception instanceof SettingsException         => $this->make($exception::STATUS, $exception::TYPE, $exception::TYPE === SettingsException::TYPE ? $this->line('problems.settings-error') : $exception->getMessage(), $request),
            $exception instanceof HttpExceptionInterface    => $this->make($exception->getStatusCode(), 'http-' . $exception->getStatusCode(), $exception->getMessage() !== '' ? $exception->getMessage() : $this->line('problems.http'), $request),
            default                                         => null,
        };
    }

    /** @param array<string, mixed> $extra */
    public function make(int $status, string $type, string $detail, ?Request $request = null, array $extra = []): JsonResponse
    {
        $title = $this->translator->get(self::LINES . 'problems.titles.' . $type);

        return new JsonResponse([
            'type'     => self::typeUri($type),
            'title'    => is_string($title) && $title !== self::LINES . 'problems.titles.' . $type ? $title : ucfirst(str_replace('-', ' ', $type)),
            'status'   => $status,
            'detail'   => $detail,
            'instance' => $request?->getRequestUri(),
        ] + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }

    private function line(string $key): string
    {
        $line = $this->translator->get(self::LINES . $key);

        return is_string($line) ? $line : $key;
    }
}
