<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds browser-facing protection to the JSON API.
 *
 * The API never serves executable content, so its CSP can deliberately deny
 * all resource loading. Financial and authentication responses are also
 * explicitly marked as non-cacheable.
 */
final class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request->isSecure());
    }

    public static function apply(Response $response, bool $isSecure = false): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $response->headers->set('Vary', 'Authorization');

        if ($isSecure) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
