<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REL-06: first global middleware. Refuses a non-multipart body larger than `app.max_body_kb` before anything
 * decodes it (JSON parsing, control-character scan) and before the rate limiter, so a multi-megabyte JSON body
 * costs no CPU. Multipart uploads (weekly PDF, profile photo) keep their own, larger limits (validated per route,
 * bounded by post_max_size).
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = max(1, (int) config('app.max_body_kb', 128)) * 1024;
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));

        if (! str_starts_with($contentType, 'multipart/form-data')) {
            $declared = $request->headers->get('Content-Length');
            $tooLarge = is_numeric($declared)
                ? (int) $declared > $maxBytes
                // No (valid) Content-Length, e.g. chunked: measure the raw body without decoding it.
                : strlen((string) $request->getContent()) > $maxBytes;
            if ($tooLarge) {
                throw new ApiError(413, 'The request is too large.', 'payload_too_large');
            }
        }

        return $next($request);
    }
}
