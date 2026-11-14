<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Push\PushResult;
use App\Services\Push\PushTransport;
use App\Services\Push\WebPushTransport;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Minishlink\WebPush\VAPID;

class FakePushTransport implements PushTransport
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>, ttl: int}> */
    public array $sent = [];

    /** @var array<string, PushResult> */
    public array $results = [];

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(PushSubscription $subscription, string $payload, int $ttlSeconds): PushResult
    {
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => json_decode($payload, true), 'ttl' => $ttlSeconds];

        return $this->results[$subscription->endpoint] ?? PushResult::Sent;
    }
}

const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';
const APPLE = 'https://web.push.apple.com/QGv1';

function subscription(string $endpoint = FCM): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']];
}

beforeEach(function () {
    $this->transport = new FakePushTransport;
    app()->instance(PushTransport::class, $this->transport);
});

describe('subscriptions', function () {
    it('requires authentication', function () {
        $this->postJson('/api/push/subscriptions', subscription())->assertUnauthorized();
        $this->deleteJson('/api/push/subscriptions', ['endpoint' => FCM])->assertUnauthorized();
        $this->getJson('/api/push/public-key')->assertUnauthorized();
    });

    it('returns the VAPID public key only', function () {
        config(['webpush.vapid.public_key' => 'BPublicKeyPlaceholder', 'webpush.vapid.private_key' => 'secret-private']);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/push/public-key')->assertOk()->assertExactJson(['public_key' => 'BPublicKeyPlaceholder']);
    });

    it('subscribes per device (any role), upserts the same endpoint and unsubscribes', function () {
        $user = User::factory()->admin()->create();
        $token = deviceToken($user);
        $this->withToken($token)->postJson('/api/push/subscriptions', subscription())->assertCreated();
        $this->withToken($token)->postJson('/api/push/subscriptions', subscription())->assertCreated();
        $sub = PushSubscription::query()->sole();
        expect($sub->user_id)->toBe($user->id)->and($sub->device_id)->not->toBeNull();
        $this->withToken($token)->deleteJson('/api/push/subscriptions', ['endpoint' => FCM])->assertOk();
        expect(PushSubscription::query()->count())->toBe(0);
    });

    it('moves an endpoint only between accounts signed in on the same device, never lets another user delete it', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->withToken(deviceToken($alice, 'shared-browser-000000001'))->postJson('/api/push/subscriptions', subscription())->assertCreated();
        app('auth')->forgetGuards();
        $this->flushHeaders();
        Sanctum::actingAs($bob);
        $this->deleteJson('/api/push/subscriptions', ['endpoint' => FCM])->assertOk();
        expect(PushSubscription::query()->sole()->user_id)->toBe($alice->id);
        app('auth')->forgetGuards();
        $this->withToken(deviceToken($bob, 'shared-browser-000000001'))->postJson('/api/push/subscriptions', subscription())->assertCreated();
        expect(PushSubscription::query()->sole()->user_id)->toBe($bob->id);
    });

    it('refuses (409) an endpoint owned by another user from another device (P4-01)', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->withToken(deviceToken($alice, 'alice-phone-00000000001'))->postJson('/api/push/subscriptions', subscription())->assertCreated();
        app('auth')->forgetGuards();
        $this->withToken(deviceToken($bob, 'bob-phone-0000000000001'))->postJson('/api/push/subscriptions', subscription())
            ->assertStatus(409)->assertJsonPath('code', 'push_endpoint_conflict');
        app('auth')->forgetGuards();
        $this->flushHeaders();
        Sanctum::actingAs($bob);
        $this->postJson('/api/push/subscriptions', subscription())->assertStatus(409);
        $sub = PushSubscription::query()->sole();
        expect($sub->user_id)->toBe($alice->id);
    });

    it('builds the push client without following redirects (P4-03)', function () {
        $keys = @VAPID::createVapidKeys();
        config(['webpush.vapid.subject' => 'mailto:test@example.test', 'webpush.vapid.public_key' => $keys['publicKey'], 'webpush.vapid.private_key' => $keys['privateKey']]);
        $transport = app(WebPushTransport::class);
        $m = new ReflectionMethod($transport, 'client');
        $level = error_reporting(E_ALL & ~E_USER_WARNING & ~E_WARNING & ~E_USER_NOTICE & ~E_NOTICE);
        try {
            $client = $m->invoke($transport);
        } finally {
            error_reporting($level);
        }
        $http = (new ReflectionProperty($client, 'client'))->getValue($client);
        expect($http->getConfig('allow_redirects'))->toBeFalse()
            ->and($http->getConfig('connect_timeout'))->toBe(5);
    });

    it('refuses endpoints outside the known push services (no SSRF)', function (string $endpoint) {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/push/subscriptions', subscription($endpoint))->assertStatus(422);
        expect(PushSubscription::query()->count())->toBe(0);
    })->with([
        'http' => ['http://fcm.googleapis.com/fcm/send/x'],
        'internal' => ['https://169.254.169.254/latest'],
        'look-alike' => ['https://fcm.googleapis.com.evil.test/x'],
        'suffix trick' => ['https://evilfcm.googleapis.com.attacker.test/x'],
        'port' => ['https://fcm.googleapis.com:8443/x'],
        'userinfo' => ['https://user@fcm.googleapis.com/x'],
    ]);

    it('validates the keys', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/push/subscriptions', ['endpoint' => FCM, 'keys' => ['p256dh' => '<script>', 'auth' => 'x']])->assertStatus(422);
    });

    it('keeps only the most recent subscriptions of a user', function () {
        app(SettingsService::class)->set(['push_max_subscriptions_per_user' => 2]);
        Sanctum::actingAs(User::factory()->create());
        foreach (['a', 'b', 'c'] as $i => $suffix) {
            $this->travel($i)->seconds();
            $this->postJson('/api/push/subscriptions', subscription(FCM.$suffix))->assertCreated();
        }
        expect(PushSubscription::query()->pluck('endpoint')->sort()->values()->all())->toBe([FCM.'b', FCM.'c']);
    });

    it('deletes the device subscriptions on sign-out', function () {
        $user = User::factory()->create();
        $token = deviceToken($user);
        $this->withToken($token)->postJson('/api/push/subscriptions', subscription())->assertCreated();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        expect(PushSubscription::query()->count())->toBe(0);
    });
});

describe('delivery', function () {
    it('sends a minimal payload to every subscription and deletes expired ones (404/410)', function () {
        $user = User::factory()->create();
        PushSubscription::query()->create(['user_id' => $user->id, 'endpoint_hash' => PushSubscription::hashEndpoint(FCM), 'endpoint' => FCM, 'public_key' => 'k', 'auth_token' => 'a']);
        PushSubscription::query()->create(['user_id' => $user->id, 'endpoint_hash' => PushSubscription::hashEndpoint(APPLE), 'endpoint' => APPLE, 'public_key' => 'k', 'auth_token' => 'a']);
        $this->transport->results[APPLE] = PushResult::Expired;

        (new SendWebPush($user->id, 'checkin_reminder', ['title' => 'Time to check in', 'body' => 'Hello', 'path' => '/attendance', 'secret' => 'x']))
            ->handle($this->transport, app(SettingsService::class));

        expect($this->transport->sent)->toHaveCount(2)
            ->and($this->transport->sent[0]['payload'])->toBe(['kind' => 'checkin_reminder', 'title' => 'Time to check in', 'body' => 'Hello', 'path' => '/attendance', 'tag' => 'checkin_reminder'])
            ->and($this->transport->sent[0]['ttl'])->toBe(3600)
            ->and(PushSubscription::query()->pluck('endpoint')->all())->toBe([FCM])
            ->and(PushSubscription::query()->sole()->last_success_at)->not->toBeNull();
    });

    it('keeps a subscription after a transient failure and never throws', function () {
        $user = User::factory()->create();
        PushSubscription::query()->create(['user_id' => $user->id, 'endpoint_hash' => PushSubscription::hashEndpoint(FCM), 'endpoint' => FCM, 'public_key' => 'k', 'auth_token' => 'a']);
        $this->transport->results[FCM] = PushResult::Failed;
        (new SendWebPush($user->id, 'x', ['path' => 'https://evil.test']))->handle($this->transport, app(SettingsService::class));
        expect(PushSubscription::query()->count())->toBe(1)->and($this->transport->sent[0]['payload']['path'])->toBe('/');
    });

    it('sends nothing when VAPID is not configured, push is disabled or the account is not active', function () {
        $user = User::factory()->create();
        $blocked = User::factory()->status(UserStatus::Blocked)->create();
        foreach ([$user, $blocked] as $i => $u) {
            PushSubscription::query()->create(['user_id' => $u->id, 'endpoint_hash' => PushSubscription::hashEndpoint(FCM.$i), 'endpoint' => FCM.$i, 'public_key' => 'k', 'auth_token' => 'a']);
        }
        (new SendWebPush($blocked->id, 'x'))->handle($this->transport, app(SettingsService::class));
        $this->transport->configured = false;
        (new SendWebPush($user->id, 'x'))->handle($this->transport, app(SettingsService::class));
        $this->transport->configured = true;
        app(SettingsService::class)->set(['push_enabled' => false]);
        (new SendWebPush($user->id, 'x'))->handle(app(PushTransport::class), app(SettingsService::class));
        expect($this->transport->sent)->toBe([]);
    });

    it('queues a push copy of every stored in-app notification', function () {
        Queue::fake();
        $approver = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        $approver->notifyNow(new StaffAlert('faceid_device_request', 'New Face ID device to approve', 'Ama registered Face ID.', [], false, '/admin/faceid-devices'));
        Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job) => $job->userId === $approver->id && $job->kind === 'faceid_device_request'
            && $job->payload['path'] === '/admin/faceid-devices');
    });

    it('uses the real transport only when configured', function () {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        expect((new WebPushTransport)->isConfigured())->toBeFalse();
        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);
        expect((new WebPushTransport)->isConfigured())->toBeTrue();
    });
});

it('generates a VAPID key pair on demand', function () {
    $this->artisan('attendance:vapid-keys')->expectsOutputToContain('VAPID_PUBLIC_KEY=')->assertSuccessful();
});
