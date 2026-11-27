<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every exception on an /api/* request into the one error shape the
 * API uses everywhere:
 *
 *   { "error": { "code": "not_found", "message": "…", "details": {} } }
 *
 * Domain errors throw ApiException with their own code (for example
 * "module_disabled" or "marks_locked").
 */
class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function toResponse(Throwable $e): JsonResponse
    {
        [$status, $code, $message, $details] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details],
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', $e->errors()],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Sign in to continue.', []],
            $e instanceof AuthorizationException => [403, 'forbidden', 'You do not have permission to do this.', []],
            $e instanceof ModelNotFoundException => [404, 'not_found', 'The record was not found.', []],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                self::codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : self::messageForStatus($e->getStatusCode()),
                [],
            ],
            default => [500, 'server_error', 'Something went wrong on our side.', []],
        };

        if ($status === 500 && config('app.debug')) {
            $details = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status, $headers);
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            412 => 'precondition_failed',
            419 => 'session_expired',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    private static function messageForStatus(int $status): string
    {
        return match ($status) {
            404 => 'The page or record was not found.',
            405 => 'This method is not allowed here.',
            429 => 'Too many requests. Please wait and try again.',
            default => 'The request could not be completed.',
        };
    }
}
