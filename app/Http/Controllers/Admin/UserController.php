<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController
{
    public function __construct(private readonly AccountService $accounts) {}

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
