<?php

namespace App\Domain\Attendance;

use App\Models\CheckinIntent;
use App\Models\User;

/**
 * Verifies the Face ID (WebAuthn passkey) assertion that confirms a check-in intent.
 * Phase 4 binds the real WebAuthn implementation; until then nothing can be verified.
 */
interface CheckinAssertionVerifier
{
    /** @param array<string, mixed> $assertion */
    public function verify(User $user, CheckinIntent $intent, array $assertion): bool;
}
