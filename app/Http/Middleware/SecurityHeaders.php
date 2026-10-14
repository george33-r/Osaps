<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for every page and API response.
 *
 * The Content-Security-Policy allows only this site's own scripts, so injected script tags do
 * not run; pages cannot be framed (clickjacking); and HTTPS is pinned once the site is on it.
 * Paystack is reached by a full-page redirect, which a CSP does not restrict.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                // React style attributes and the one inline background rule in index.html.
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "media-src 'self' blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                'frame-src https://www.youtube-nocookie.com',
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "object-src 'none'",
            ]).($request->isSecure() ? '; upgrade-insecure-requests' : ''));
        }

        if ($request->isSecure() && app()->environment('production')) {
            // Six months. Add "; preload" only once every subdomain is on HTTPS for good.
            $h->set('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        return $response;
    }
}
