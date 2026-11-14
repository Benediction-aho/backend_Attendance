<?php

namespace App\Domain\Attendance;

use App\Domain\FaceId\VerifiedAssertion;
use App\Models\CheckinIntent;
use App\Models\User;

/**
 * Verifies the Face ID (WebAuthn passkey) assertion that confirms a check-in intent.
 * verify() only checks; markUsed() consumes the challenge inside the check-in transaction.
 */
interface CheckinAssertionVerifier
{
    /** @param array<string, mixed> $assertion */
    public function verify(User $user, CheckinIntent $intent, array $assertion): ?VerifiedAssertion;

    public function markUsed(VerifiedAssertion $verified): bool;
}
