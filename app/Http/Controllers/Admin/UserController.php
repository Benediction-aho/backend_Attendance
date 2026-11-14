<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountService;
use App\Support\SignupText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, UserStatus::cases()))],
            'role' => ['nullable', Rule::in(array_map(fn ($r) => $r->value, Role::cases()))],
            'employee_type' => ['nullable', Rule::in(EmployeeType::values())],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $actor = $this->actor($request);

        $query = User::query()
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('role', Role::Employee->value))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when($filters['employee_type'] ?? null, fn ($q, $v) => $q->where('employee_type', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) $v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('last_name')->orderBy('first_name');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => UserResource::collection($page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        // REL-10: authorize before validating, so a caller without the right never learns the expected fields.
        $requestedRole = $request->input('role');
        Gate::authorize('create', [User::class, (is_string($requestedRole) ? Role::tryFrom($requestedRole) : null) ?? Role::Employee]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:'.SignupText::EMAIL_PATTERN],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            ...EmployeeType::rules(),
            'job_title' => ['required', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(array_map(fn ($r) => $r->value, Role::cases()))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_map(fn ($p) => $p->value, Permission::cases()))],
        ], EmployeeType::messages());
        $role = Role::from($data['role'] ?? Role::Employee->value);
        Gate::authorize('create', [User::class, $role]);

        $user = $this->accounts->create([
            'email' => $data['email'],
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'employee_type' => $data['employee_type'],
            'employee_type_other' => EmployeeType::otherText($data['employee_type'], $data['employee_type_other'] ?? null),
            'job_title' => trim($data['job_title']),
            'role' => $role->value,
        ], $this->actor($request), array_values($data['permissions'] ?? []));

        return response()->json([
            'message' => $user->isSuperAdmin() ? 'Super admin created.' : 'Account created. The identification code has been emailed.',
            'data' => new UserResource($user->refresh()),
        ], 201);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        Gate::authorize('block', $user);
        $this->accounts->block($user, $this->actor($request));

        return response()->json(['message' => 'Account blocked.', 'data' => new UserResource($user->refresh())]);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        Gate::authorize('block', $user);
        $this->accounts->unblock($user, $this->actor($request));

        return response()->json(['message' => 'Account unblocked.', 'data' => new UserResource($user->refresh())]);
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        Gate::authorize('unlock', $user);
        $data = $request->validate(['send_new_code' => ['nullable', 'boolean']]);
        $this->accounts->unlock($user, $this->actor($request), (bool) ($data['send_new_code'] ?? false));

        return response()->json(['message' => 'Access restored.', 'data' => new UserResource($user->refresh())]);
    }

    public function regenerateCode(Request $request, User $user): JsonResponse
    {
        Gate::authorize('regenerateCode', $user);
        $this->accounts->regenerateCode($user, $this->actor($request));

        return response()->json(['message' => 'A new identification code has been emailed.', 'data' => new UserResource($user->refresh())]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);
        $this->accounts->delete($user, $this->actor($request));

        return response()->json(['message' => 'Account deleted.']);
    }

    public function permissions(Request $request, User $user): JsonResponse
    {
        Gate::authorize('managePermissions', $user);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(array_map(fn ($p) => $p->value, Permission::cases()))],
        ]);
        $this->accounts->syncPermissions($user, array_values(array_unique($data['permissions'])), $this->actor($request));

        return response()->json(['message' => 'Permissions updated.', 'data' => new UserResource($user->refresh())]);
    }

    public function role(Request $request, User $user): JsonResponse
    {
        Gate::authorize('changeRole', $user);
        $data = $request->validate(['role' => ['required', Rule::in(array_map(fn ($r) => $r->value, Role::cases()))]]);
        $this->accounts->changeRole($user, Role::from($data['role']), $this->actor($request));

        return response()->json(['message' => 'Role updated.', 'data' => new UserResource($user->refresh())]);
    }

    public function permissionCatalog(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (Permission $p) => ['key' => $p->value, 'label' => $p->label()], Permission::cases())]);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        Gate::authorize('approve', $user);
        $this->accounts->approve($user, $this->actor($request));

        return response()->json(['message' => 'Account approved. The identification code has been emailed.', 'data' => new UserResource($user->refresh())]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        Gate::authorize('approve', $user);
        $this->accounts->reject($user, $this->actor($request));

        return response()->json(['message' => 'Request rejected.']);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
