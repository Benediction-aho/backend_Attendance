<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5-01: rejects any request whose input (query, form or JSON; keys and values) contains NUL or another
 * control character, including C1 controls U+0080-U+009F (P5-11). Tab, line feed and carriage return stay allowed (multi-line free text); single-line
 * fields collapse them server-side. PostgreSQL drivers truncate text at NUL, so accepting it would make
 * the acknowledged value differ from the stored one.
 */
class RejectControlCharacters
{
    private const PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->contains($request->query->all()) || $this->contains($request->request->all())
            || ($request->isJson() && $this->contains($request->json()->all()))) {
            throw new ApiError(422, 'The request contains characters that are not allowed.', 'invalid_characters');
        }

        return $next($request);
    }

    private function contains(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match(self::PATTERN, $value) !== 0;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((is_string($key) && preg_match(self::PATTERN, $key) !== 0) || $this->contains($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
