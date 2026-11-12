<?php

namespace App\Http\Controllers;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Errors that happened in the employee's app (phone/browser), attached to the account (SPECS 9 bis).
 */
class ClientErrorController
{
    public function store(Request $request, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'string', 'max:200'],
            'platform' => ['nullable', 'string', 'max:100'],
            'stack' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $logger->log('client.error', $data['message'], LogType::Error, LogLevel::Error, $user, $user, array_filter([
            'app_version' => $data['app_version'] ?? null,
            'page' => $data['page'] ?? null,
            'platform' => $data['platform'] ?? null,
            'stack' => $data['stack'] ?? null,
        ]));

        return response()->json(['message' => 'Recorded.'], 202);
    }
}
