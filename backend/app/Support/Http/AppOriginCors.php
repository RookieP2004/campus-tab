<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for the Android, iOS, Windows and macOS apps (Phase 9). Their web
 * views load the app from a local origin (https://localhost,
 * capacitor://localhost, http://tauri.localhost, tauri://localhost) and
 * call the API with a bearer token kept in memory.
 *
 * These origins are allowed WITHOUT credentials: a browser never sends the
 * session cookie to the API on their behalf, so a page that happens to run
 * on https://localhost on someone's computer cannot use their web session.
 * The web app's own origin (FRONTEND_URL) keeps Laravel's credentialed CORS
 * (config/cors.php).
 */
class AppOriginCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || ! $request->is('api/*') || ! in_array($origin, self::origins(), true)) {
            return $next($request);
        }

        $response = $request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')
            ? response('', 204)
            : $next($request);

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->remove('Access-Control-Allow-Credentials');
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Origin', ', '));
        if ($request->isMethod('OPTIONS')) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Accept-Language, Idempotency-Key, If-Match, X-Background-Request');
            $response->headers->set('Access-Control-Max-Age', '600');
        }
        $response->headers->set('Access-Control-Expose-Headers', 'ETag, Content-Disposition, X-Licence-State, Idempotent-Replayed');

        return $response;
    }

    /** @return list<string> */
    public static function origins(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('cors.app_origins', '')))));
    }
}
