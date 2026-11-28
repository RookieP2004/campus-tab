<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every API answer (Phase 9 security pass).
 *
 *  - Content-Security-Policy: the API serves JSON and files, never pages,
 *    so nothing may run or be framed (default-src 'none',
 *    frame-ancestors 'none'). The Horizon dashboard (local only) is left
 *    alone; it is never reachable in production.
 *  - Referrer-Policy: no-referrer, so links that carry a token never leak
 *    it onwards.
 *  - X-Content-Type-Options, X-Frame-Options (older browsers),
 *    Permissions-Policy, Cross-Origin-Opener-Policy.
 *  - Strict-Transport-Security in production only (the local stack and
 *    the phone test on Wi-Fi run over plain HTTP).
 *  - API answers are never stored by caches (personal data).
 *
 * The web app's own pages get their headers from the web server
 * (infra/docker/edge/nginx.conf).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $headers->remove('X-Powered-By');

        if (! $request->is('horizon', 'horizon/*')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // A public file (the school logo) keeps the caching its route chose.
        if ($request->is('api/*') && ! str_contains((string) $headers->get('Cache-Control'), 'public')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if (app()->environment('production')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
