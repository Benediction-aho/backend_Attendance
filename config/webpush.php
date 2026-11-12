<?php

/*
 * Web Push (VAPID). Generate the key pair once per environment (see DEPLOYMENT.md) and keep the private key
 * in the host's secrets only. Without keys, pushes are skipped (the in-app notification is always stored).
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
    // Push services the server may call (prevents using subscriptions to reach arbitrary hosts).
    'allowed_host_suffixes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'WEBPUSH_ALLOWED_HOST_SUFFIXES',
        'fcm.googleapis.com,android.googleapis.com,push.apple.com,push.services.mozilla.com,notify.windows.com',
    ))))),
];
