<?php

/*
 * Face ID (WebAuthn passkeys). The RP ID must be the final domain of the PWA (e.g. attendance.example.com):
 * passkeys registered under one RP ID never work under another. Only the public key is stored.
 */
return [
    'rp_id' => env('WEBAUTHN_RP_ID', 'localhost'),
    'rp_name' => env('WEBAUTHN_RP_NAME', 'Assistance Ghana Attendance'),
    // Exact origins of the PWA (scheme://host[:port]), comma-separated.
    'origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBAUTHN_ORIGINS', 'http://localhost:5173'))))),
];
