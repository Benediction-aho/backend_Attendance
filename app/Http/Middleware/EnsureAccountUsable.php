<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum on every protected route:
 * - a blocked, locked, pending or deleted account loses access immediately (all its tokens are revoked);
 * - a device token unused for longer than the configured idle period expires (sliding session, FIABILITE B3);
 * - until the identification code is replaced, only the routes needed to change it are reachable.
 */
class EnsureAccountUsable
{
    /** Route names reachable while a password change is required. */
    private const ALLOWED_DURING_FORCED_CHANGE = ['me.show', 'me.password', 'auth.logout'];

    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticated();
        }

        if (! $user->isActive()) {
            $user->tokens()->delete();

            return $this->unauthenticated();
        }

        $token = $user->currentAccessToken();
        if ($token->exists) {
            $lastUsed = $token->last_used_at ?? $token->created_at;
            if ($lastUsed !== null && $lastUsed->lt(Carbon::now()->subDays($this->settings->int('session_idle_days')))) {
                $token->delete();

                return $this->unauthenticated();
            }
            $deviceId = $token->getAttribute('device_id');
            if ($deviceId !== null) {
                $request->attributes->set('device_id', (int) $deviceId);
            }
        }

        if ($user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED_DURING_FORCED_CHANGE, true)) {
            return response()->json([
                'message' => 'You must change your identification code before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
