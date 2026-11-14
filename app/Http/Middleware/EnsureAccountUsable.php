<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SessionRevoker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum on every protected route:
 * - a blocked, locked, pending or deleted account loses access immediately (all its tokens are revoked);
 * - until the identification code is replaced, only the routes needed to change it are reachable.
 */
class EnsureAccountUsable
{
    /** Route names reachable while a password change is required. */
    private const ALLOWED_DURING_FORCED_CHANGE = ['me.show', 'me.password', 'auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticated();
        }

        if (! $user->isActive()) {
            SessionRevoker::revokeForAccountState($user, $user->status->value);

            return response()->json(['message' => 'Unauthenticated.', 'code' => 'account_blocked'], 401);
        }

        $token = $user->currentAccessToken();
        if ($token->exists) {
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
