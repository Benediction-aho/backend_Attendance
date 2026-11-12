<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every error as a generic JSON body. Never exposes exception
 * messages, classes, files or traces unless APP_DEBUG is true.
 */
class ApiExceptionRenderer
{
    /**
     * Generic English messages per status code.
     *
     * @var array<int, string>
     */
    private const MESSAGES = [
        400 => 'Bad request.',
        401 => 'Unauthenticated.',
        403 => 'Forbidden.',
        404 => 'Not found.',
        405 => 'Method not allowed.',
        408 => 'Request timeout.',
        409 => 'Conflict.',
        413 => 'Payload too large.',
        415 => 'Unsupported media type.',
        419 => 'Session expired.',
        422 => 'The given data was invalid.',
        429 => 'Too many requests.',
        500 => 'Something went wrong. Please try again later.',
        503 => 'Service temporarily unavailable.',
    ];

    public function __invoke(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => self::MESSAGES[422],
                'errors' => $e->errors(),
            ], 422);
        }

        $status = match (true) {
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        $body = ['message' => self::MESSAGES[$status] ?? ($status >= 500 ? self::MESSAGES[500] : self::MESSAGES[400])];

        if (config('app.debug') === true) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return response()->json($body, $status, $headers);
    }
}
