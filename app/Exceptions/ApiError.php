<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A business error whose message is written for the user (English, no internals) and safe to return.
 */
class ApiError extends HttpException
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public function __construct(int $status, string $message, public readonly ?string $errorCode = null, public readonly array $extra = [], array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
