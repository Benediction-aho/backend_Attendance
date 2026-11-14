<?php

namespace App\Domain\FaceId;

use App\Domain\Attendance\CheckinAssertionVerifier;
use App\Models\CheckinIntent;
use App\Models\User;

class WebAuthnAssertionVerifier implements CheckinAssertionVerifier
{
    public function __construct(private readonly WebAuthnService $webauthn) {}

    public function verify(User $user, CheckinIntent $intent, array $assertion): ?VerifiedAssertion
    {
        return $this->webauthn->verifyCheckin($user, $intent, $assertion);
    }

    public function markUsed(VerifiedAssertion $verified): bool
    {
        return $this->webauthn->markUsed($verified);
    }
}
