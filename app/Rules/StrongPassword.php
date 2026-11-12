<?php

namespace App\Rules;

use App\Settings\SettingsService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Minimum length from settings, at least one letter and one digit, not a common password.
 */
class StrongPassword implements ValidationRule
{
    private const COMMON = [
        'password', 'password1', 'password123', '123456789', '1234567890', 'qwerty123', 'azerty123',
        'iloveyou', 'welcome1', 'welcome123', 'admin123', 'letmein', 'abc123456', 'passw0rd', 'attendance',
        'attendance1', 'attendance123', 'ghana123', 'accra123', 'assistance', 'qwertyuiop', '1q2w3e4r5t',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $min = app(SettingsService::class)->int('password_min_length');

        if (mb_strlen($value) < $min) {
            $fail("The password must be at least {$min} characters.");

            return;
        }
        if (! preg_match('/\pL/u', $value) || ! preg_match('/\d/', $value)) {
            $fail('The password must contain letters and numbers.');

            return;
        }
        if (in_array(mb_strtolower($value), self::COMMON, true)) {
            $fail('This password is too common. Please choose another one.');
        }
    }
}
