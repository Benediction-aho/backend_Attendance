<?php

namespace App\Http\Controllers;

use App\Domain\FaceId\WebAuthnService;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ApiError;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Face ID (WebAuthn passkeys): employee registration and admin review (SPECS 6, security.md).
 * Only the fields the caller may see are returned; the public key never leaves the server.
 */
class FaceIdController
{
    public function __construct(private readonly WebAuthnService $webauthn) {}

    /** The employee's own Face ID status on this device and her credentials. */
    public function mine(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $deviceId = $this->deviceId($request);
        $credentials = WebauthnCredential::query()->with('device')->where('user_id', $user->id)
            ->where('status', '!=', WebauthnCredential::REVOKED)->orderByDesc('created_at')->get();

        return response()->json([
            'state' => $this->webauthn->state($user, $deviceId),
            'data' => $credentials->map(fn (WebauthnCredential $c) => $this->present($c) + ['this_device' => $c->device_id !== null && $c->device_id === $deviceId])->values(),
        ]);
    }

    public function registrationOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $deviceId = $this->requireDevice($request);
        if ($this->webauthn->state($user, $deviceId) === 'ready') {
            throw new ApiError(409, 'Face ID is already registered on this device.', 'faceid_already_registered');
        }

        return response()->json($this->webauthn->registrationOptions($user, $deviceId), 201);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1400'],
            'credential.rawId' => ['required', 'string', 'max:1400'],
            'credential.type' => ['required', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string', 'max:4096'],
            'credential.response.attestationObject' => ['required', 'string', 'max:16384'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $this->user($request);
        $credential = $this->webauthn->register($user, $this->requireDevice($request), $data['challenge_id'], $data['credential'], $data['name'] ?? null);

        return response()->json(['data' => $this->present($credential), 'state' => $this->webauthn->state($user, $this->deviceId($request))], 201);
    }

    /** Admin list (approve_faceid_devices). Admins only see employees' devices. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->user($request);
        $this->requireTier($actor);
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,revoked'],
            'user_id' => ['nullable', 'integer'],
        ]);
        $page = WebauthnCredential::query()->with(['user', 'device'])
            ->where('status', $data['status'] ?? WebauthnCredential::PENDING)
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->whereIn('user_id', User::query()->select('id')->where('role', Role::Employee->value)))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->orderByDesc('created_at')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (WebauthnCredential $c) => $this->present($c) + [
                'user' => $c->user !== null ? ['id' => $c->user->id, 'first_name' => $c->user->first_name, 'last_name' => $c->user->last_name] : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function approve(Request $request, WebauthnCredential $credential): JsonResponse
    {
        $actor = $this->authorizeReview($request, $credential);

        return response()->json(['data' => $this->present($this->webauthn->approve($actor, $credential))]);
    }

    public function reject(Request $request, WebauthnCredential $credential): JsonResponse
    {
        $actor = $this->authorizeReview($request, $credential);

        return response()->json(['data' => $this->present($this->webauthn->reject($actor, $credential))]);
    }

    /** Super admins can revoke any credential. */
    public function revoke(Request $request, WebauthnCredential $credential): JsonResponse
    {
        $actor = $this->user($request);
        if (! $actor->isSuperAdmin()) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return response()->json(['data' => $this->present($this->webauthn->revoke($actor, $credential))]);
    }

    private function authorizeReview(Request $request, WebauthnCredential $credential): User
    {
        $actor = $this->user($request);
        $this->requireTier($actor);
        $owner = $credential->user;
        if ($owner === null || $owner->id === $actor->id || (! $actor->isSuperAdmin() && $owner->role !== Role::Employee)) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return $actor;
    }

    private function requireTier(User $actor): void
    {
        if (! $actor->hasPermission(Permission::ApproveFaceIdDevices)) {
            throw new ApiError(403, 'This action is not allowed.');
        }
    }

    /** @return array<string, mixed> */
    private function present(WebauthnCredential $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'device' => $c->device !== null ? ['id' => $c->device->id, 'name' => $c->device->name] : null,
            'created_at' => $c->created_at->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'revoked_at' => $c->revoked_at?->toIso8601String(),
            'revoke_reason' => $c->revoke_reason,
            'last_used_at' => $c->last_used_at?->toIso8601String(),
        ];
    }

    private function requireDevice(Request $request): int
    {
        return $this->deviceId($request) ?? throw new ApiError(403, 'Please sign in again on this device.', 'device_required');
    }

    private function deviceId(Request $request): ?int
    {
        $id = $request->attributes->get('device_id');

        return is_int($id) ? $id : null;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiError(401, 'Unauthenticated.');
    }
}
