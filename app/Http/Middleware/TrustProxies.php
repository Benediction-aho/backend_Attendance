<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trusted proxies come from configuration (TRUSTED_PROXIES), so the client IP
 * used for rate limiting and device lockout is correct behind a load balancer.
 */
class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return null;
        }

        if ($configured === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
