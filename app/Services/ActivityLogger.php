<?php

namespace App\Services;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the append-only activity log (SPECS 9 bis, security.md section 6).
 * Never stores passwords, codes or tokens: sensitive context keys are dropped.
 */
class ActivityLogger
{
    private const SENSITIVE = ['password', 'code', 'token', 'secret', 'current_password', 'new_password', 'otp'];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        string $event,
        string $description,
        LogType $type = LogType::Action,
        LogLevel $level = LogLevel::Info,
        ?User $user = null,
        ?User $actor = null,
        array $context = [],
    ): ?ActivityLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $deviceId = $request instanceof Request ? $request->attributes->get('device_id') : null;

        try {
            return ActivityLog::query()->create([
                'user_id' => $user?->id,
                'actor_id' => $actor?->id,
                'type' => $type,
                'level' => $level,
                'event' => Str::limit($event, 80, ''),
                'description' => Str::limit($description, 500, ''),
                'context' => $context === [] ? null : $this->scrub($context),
                'ip' => $request?->ip(),
                'device_id' => is_int($deviceId) ? $deviceId : null,
                'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 500, '') : null,
            ]);
        } catch (Throwable $e) {
            // Not report(): that would try to write the activity log again.
            Log::error('Activity log write failed', ['exception' => $e::class]);

            return null;
        }
    }

    /** @param array<string, mixed> $context */
    public function security(string $event, string $description, LogLevel $level, ?User $user = null, ?User $actor = null, array $context = []): ?ActivityLog
    {
        return $this->log($event, $description, LogType::Security, $level, $user, $actor, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scrub(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }
}
