<?php

namespace App\Domain\Attendance;

use App\Models\CheckinIntent;
use App\Models\User;

/** Phase 3 placeholder: no passkey support yet, so no assertion is ever accepted as verified. */
class UnavailableAssertionVerifier implements CheckinAssertionVerifier
{
    public function verify(User $user, CheckinIntent $intent, array $assertion): bool
    {
        return false;
    }
}
