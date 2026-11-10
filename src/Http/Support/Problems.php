<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Support;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\UnknownOwner;
use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Exceptions\HistoryUnavailable;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * RFC 9457 problem details (`application/problem+json`) for the REST API (§16.10): one `type` per
 * exception, the status the design maps it to, and the exception's message as `detail` (the
 * package's messages name stores, keys and scopes, never a value). Anything the package does not
 * know is left to the application's own handler.
 */
final readonly class Problems
{
    public const string TYPE_BASE = 'https://opensource.simtabi.com/documentation/laranail/settings/problems/';

    /** @var array<class-string<Throwable>, array{int, string}> */
    private const array MAP = [
        AccessDenied::class           => [403, 'access-denied'],
        ActorRequired::class          => [401, 'actor-required'],
        VerificationRequired::class   => [428, 'verification-required'],
        UnknownStore::class           => [404, 'unknown-store'],
        UnknownSetting::class         => [404, 'unknown-setting'],
        UnknownOwner::class           => [404, 'unknown-owner'],
        UnknownCategory::class        => [404, 'unknown-category'],
        UnknownChangeRequest::class   => [404, 'unknown-change-request'],
        InvalidSettingValue::class    => [422, 'invalid-value'],
        InvalidIdentifier::class      => [422, 'invalid-identifier'],
        InvalidImport::class          => [422, 'invalid-import'],
        InvalidDefinition::class      => [422, 'invalid-definition'],
        InvalidConfiguration::class   => [422, 'invalid-configuration'],
        TypeMismatch::class           => [422, 'type-mismatch'],
        CategoryCycle::class          => [422, 'category-cycle'],
        CategoryStoreMismatch::class  => [422, 'category-store-mismatch'],
        LockedSetting::class          => [423, 'locked'],
        ConcurrentModification::class => [412, 'precondition-failed'],
        ApprovalUnavailable::class    => [409, 'approval-unavailable'],
        ChangeRequestClosed::class    => [409, 'change-request-closed'],
        OwnerNotAllowed::class        => [409, 'owner-not-allowed'],
        StoreNotWritable::class       => [409, 'store-not-writable'],
        HistoryUnavailable::class     => [409, 'history-unavailable'],
        DriverUnavailable::class      => [503, 'unavailable'],
    ];

    /** The problem response for an exception raised by an API route, or null to leave it alone. */
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'getName') || ! str_starts_with((string) $route->getName(), 'laranail-settings.api.')) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException       => self::make(422, 'validation', 'The request is invalid.', $request, ['errors' => $exception->errors()]),
            $exception instanceof AuthenticationException   => self::make(401, 'unauthenticated', 'Authentication is required.', $request),
            $exception instanceof ThrottleRequestsException => self::make(429, 'too-many-requests', 'Too many requests.', $request),
            $exception instanceof SettingsException         => self::forSettings($exception, $request),
            $exception instanceof HttpExceptionInterface    => self::make($exception->getStatusCode(), 'http-' . $exception->getStatusCode(), $exception->getMessage() !== '' ? $exception->getMessage() : 'The request failed.', $request),
            default                                         => null,
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function make(int $status, string $type, string $detail, ?Request $request = null, array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'type'     => self::TYPE_BASE . $type,
            'title'    => ucfirst(str_replace('-', ' ', $type)),
            'status'   => $status,
            'detail'   => $detail,
            'instance' => $request?->getRequestUri(),
        ] + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }

    private static function forSettings(SettingsException $exception, Request $request): JsonResponse
    {
        foreach (self::MAP as $class => [$status, $type]) {
            if ($exception instanceof $class) {
                return self::make($status, $type, $exception->getMessage(), $request);
            }
        }

        return self::make(500, 'settings-error', 'The settings operation failed.', $request);
    }
}
