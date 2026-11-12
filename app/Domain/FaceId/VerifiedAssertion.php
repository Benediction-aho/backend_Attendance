<?php

namespace App\Domain\FaceId;

/** A cryptographically verified check-in assertion, not yet consumed. */
final class VerifiedAssertion
{
    public function __construct(
        public readonly int $credentialId,
        public readonly string $challengeId,
        public readonly int $signCount,
    ) {}
}
