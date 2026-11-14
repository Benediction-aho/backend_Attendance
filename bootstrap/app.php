<?php

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureCheckedInToday;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\RejectControlCharacters;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // REL-06: oversized bodies are refused before any parsing (SecurityHeaders still wraps the answer).
        $middleware->prepend(LimitRequestBody::class);
        $middleware->prepend(SecurityHeaders::class);
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);
        $middleware->append(RejectControlCharacters::class);
        $middleware->throttleApi();
        $middleware->alias(['checked-in' => EnsureCheckedInToday::class]);
        // API only: guests are never redirected to a "login" route, they get a JSON 401 (P1-04).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only: every error is JSON, never an HTML page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        // Server errors are also recorded in the activity log, attached to the signed-in account (SPECS 9 bis).
        $exceptions->report(function (Throwable $e) {
            if ($e instanceof HttpExceptionInterface || $e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof AuthorizationException) {
                return;
            }
            try {
                $user = request()->user('sanctum');
                app(ActivityLogger::class)->log(
                    'server.error',
                    'Server error: '.class_basename($e),
                    LogType::Error,
                    LogLevel::Error,
                    $user instanceof User ? $user : null,
                    context: ['exception' => $e::class, 'path' => request()->path()],
                );
            } catch (Throwable) {
                // never let logging break error handling
            }
        });
        $exceptions->render(fn (Throwable $e, Request $request) => (new ApiExceptionRenderer)($e, $request));
    })->create();
