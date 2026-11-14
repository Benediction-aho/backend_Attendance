<?php

namespace App\Http\Controllers\Dashboard;

use App\Domain\Dashboard\DashboardService;
use App\Exceptions\ApiError;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase 6 dashboard summaries; all figures are computed server-side (DashboardService). */
class DashboardController
{
    public function __construct(private readonly DashboardService $service) {}

    public function employee(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->service->employee($user)]);
    }

    public function admin(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->isStaff()) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return response()->json(['data' => $this->service->admin($user)]);
    }
}
