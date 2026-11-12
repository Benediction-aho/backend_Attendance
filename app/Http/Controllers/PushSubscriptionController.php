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

        DB::transaction(function () use ($user, $deviceId, $hash, $data) {
            // Upsert on the endpoint: a browser endpoint belongs to whoever is signed in on that browser now.
            PushSubscription::query()->upsert([[
                'user_id' => $user->id,
                'device_id' => is_int($deviceId) ? $deviceId : null,
                'endpoint_hash' => $hash,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['endpoint_hash'], ['user_id', 'device_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'updated_at']);

            // Keep only the most recent subscriptions of the user.
            $max = $this->settings->int('push_max_subscriptions_per_user');
            $keep = PushSubscription::query()->where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')->limit($max)->pluck('id');
            PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
        });
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
