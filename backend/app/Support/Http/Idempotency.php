<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `idempotent`. A create or workflow action sent with an
 * Idempotency-Key header runs once: a retry with the same key (a double tap,
 * a network retry) gets the first answer back, marked with
 * Idempotent-Replayed: true, instead of doing the action again.
 *
 *  - The key belongs to one person: the cache key holds the tenant, the user
 *    and the route, so nobody can replay someone else's answer.
 *  - The same key with a different body is refused (422
 *    idempotency_key_reused).
 *  - Two requests with the same key at the same moment: the second gets 409
 *    idempotency_in_progress.
 *  - Only successful (2xx) JSON answers are kept, for 24 hours. A refused
 *    action can be tried again with the same key.
 *  - Without the header the request runs as usual.
 */
class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public const HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            return $next($request);
        }

        if (strlen($key) > 100 || preg_match('/^[A-Za-z0-9_\-:.]+$/', $key) !== 1) {
            throw new ApiException('idempotency_key_invalid', 'The Idempotency-Key header must be up to 100 letters, digits, dashes or colons.', 422);
        }

        $user = $request->user();
        $who = $user === null ? 'guest' : (($user->tenant_id ?? 'platform').':'.$user->getAuthIdentifier());
        $cacheKey = 'idem:'.hash('sha256', $who.'|'.$request->method().'|'.$request->path().'|'.$key);
        $fingerprint = hash('sha256', (string) json_encode($request->except(['_token'])).'|'.count($request->allFiles()));

        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            if (($stored['fingerprint'] ?? null) !== $fingerprint) {
                throw new ApiException('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.', 422);
            }

            return response()->json($stored['body'], (int) $stored['status'])->header('Idempotent-Replayed', 'true');
        }

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            throw new ApiException('idempotency_in_progress', 'The same request is still being handled. Wait a moment and look again.', 409);
        }

        try {
            $response = $next($request);

            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getData(true),
                ], now()->addHours(self::HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
