<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trusted proxies come from configuration (TRUSTED_PROXIES), so the client IP
 * used for rate limiting and device lockout is correct behind a load balancer.
 * With "*", only the immediate peer (REMOTE_ADDR, i.e. the hosting provider's proxy) is trusted: the client IP is
 * the right-most X-Forwarded-For entry, the one that proxy appends; entries a client adds on the left are ignored.
 */
class TrustProxies extends Middleware
{
    /**
     * REL-05: only the client IP and the scheme are taken from the proxy. Host, port and prefix never come from
     * request headers (links are built from APP_URL), so a forged X-Forwarded-Host cannot change a generated URL.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * REL-19: several X-Forwarded-For header lines are joined in order (RFC 9110 field combination) so the
     * right-most entry, the one appended by the trusted proxy, is used, never the first client-chosen line.
     */
    public function handle(Request $request, Closure $next)
    {
        $lines = $request->headers->all('x-forwarded-for');
        if (count($lines) > 1) {
            $request->headers->set('X-Forwarded-For', implode(', ', array_map(fn ($l) => trim((string) $l), $lines)));
        }

        return parent::handle($request, $next);
    }

    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return null;
        }

        // REL-05: Laravel's own "*" trusts every address, so a client-chosen left-most X-Forwarded-For entry would
        // become the client IP. Here "*" means the immediate peer only (REMOTE_ADDR, the hosting provider's proxy,
        // whose internal address is not fixed): the client IP is the entry that proxy appended. Extra hops in front
        // (e.g. a CDN) must be listed explicitly as CIDRs, together with "REMOTE_ADDR" if needed.
        if ($configured === '*' || $configured === '**') {
            return ['REMOTE_ADDR'];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
