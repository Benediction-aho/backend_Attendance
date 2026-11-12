<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes headers that reveal the technology stack and adds a strict
 * baseline of security headers to every response (docs/security.md, section 3).
 */
class SecurityHeaders
{
    /**
     * Headers that must never leave the server.
     *
     * @var list<string>
     */
    private const REMOVED = ['X-Powered-By', 'Server', 'X-Generator'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! headers_sent()) {
            foreach (self::REMOVED as $header) {
                header_remove($header);
            }
        }

        foreach (self::REMOVED as $header) {
            $response->headers->remove($header);
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (str_starts_with($request->path(), 'api')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
