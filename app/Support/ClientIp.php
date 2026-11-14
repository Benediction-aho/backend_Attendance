<?php

namespace App\Support;

/**
 * REL-17: rate-limit bucket for a client address. IPv4 is kept as is; an IPv4-mapped IPv6 address becomes its
 * IPv4; any other IPv6 address is reduced to its /64 prefix (one subscriber usually owns a whole /64, so per-/128
 * buckets would give no protection).
 */
final class ClientIp
{
    public static function bucket(?string $ip): string
    {
        $ip = (string) $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
