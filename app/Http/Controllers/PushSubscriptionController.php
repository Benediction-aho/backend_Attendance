<?php

namespace App\Http\Controllers;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Web Push subscriptions of the signed-in user, one per browser endpoint, linked to this device session. */
class PushSubscriptionController
{
    public function __construct(private readonly SettingsService $settings, private readonly ActivityLogger $logger) {}

    public function publicKey(): JsonResponse
    {
        $key = config('webpush.vapid.public_key');

        return response()->json(['public_key' => is_string($key) && $key !== '' ? $key : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);
        if (! $this->allowedEndpoint($data['endpoint'])) {
            throw new ApiError(422, 'This push service is not supported.', 'push_endpoint_invalid');
        }
        $user = $this->user($request);
        $deviceId = $request->attributes->get('device_id');
        $hash = PushSubscription::hashEndpoint($data['endpoint']);

        $device = is_int($deviceId) ? $deviceId : null;
        $bound = DB::transaction(function () use ($user, $device, $hash, $data) {
            // Upsert on the endpoint. An endpoint owned by another user is only re-bound when the request
            // comes from the same device session (shared browser); otherwise it is refused (P4-01).
            $now = now();
            $rows = DB::select(
                'INSERT INTO push_subscriptions (user_id, device_id, endpoint_hash, endpoint, public_key, auth_token, content_encoding, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (endpoint_hash) DO UPDATE SET user_id = excluded.user_id, device_id = excluded.device_id,
                   endpoint = excluded.endpoint, public_key = excluded.public_key, auth_token = excluded.auth_token,
                   content_encoding = excluded.content_encoding, updated_at = excluded.updated_at
                 WHERE push_subscriptions.user_id = excluded.user_id
                    OR (excluded.device_id IS NOT NULL AND EXISTS (
                         SELECT 1 FROM devices old_d JOIN devices new_d ON new_d.identifier_hash = old_d.identifier_hash
                         WHERE old_d.id = push_subscriptions.device_id AND new_d.id = excluded.device_id))
                 RETURNING id',
                [$user->id, $device, $hash, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['content_encoding'] ?? 'aes128gcm', $now, $now],
            );
            if ($rows === []) {
                return false;
            }

            // Keep only the most recent subscriptions of the user.
            $max = $this->settings->int('push_max_subscriptions_per_user');
            $keep = PushSubscription::query()->where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')->limit($max)->pluck('id');
            PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

            return true;
        });
        if (! $bound) {
            $this->logger->security('push.subscribe_conflict', 'Push subscription refused: endpoint owned by another account', LogLevel::Warning, $user, $user);
            throw new ApiError(409, 'This push subscription is already in use.', 'push_endpoint_conflict');
        }
        $this->logger->security('push.subscribed', 'Push notifications enabled on a device', LogLevel::Info, $user, $user);

        return response()->json(['message' => 'Push notifications enabled.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $user = $this->user($request);
        PushSubscription::query()->where('user_id', $user->id)->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))->delete();

        return response()->json(['message' => 'Push notifications disabled.']);
    }

    private function allowedEndpoint(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if ($host === '' || parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || parse_url($endpoint, PHP_URL_PORT) !== null || parse_url($endpoint, PHP_URL_USER) !== null) {
            return false;
        }
        foreach ((array) config('webpush.allowed_host_suffixes', []) as $suffix) {
            $suffix = strtolower((string) $suffix);
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return true;
            }
        }

        return false;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiError(401, 'Unauthenticated.');
    }
}
