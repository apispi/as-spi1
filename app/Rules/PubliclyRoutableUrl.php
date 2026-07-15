<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Blocks obvious SSRF targets (loopback, private/link-local ranges, cloud
 * metadata hosts) for URLs that this app's proxy/test endpoints will make
 * outbound requests to on the user's behalf.
 *
 * This does not resolve DNS, so it does not stop DNS-rebinding attacks where
 * a public hostname later resolves to an internal address — only requests
 * where the host is itself a blocked literal or well-known internal name.
 */
class PubliclyRoutableUrl implements ValidationRule
{
    protected const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = parse_url((string) $value);

        if (! is_array($parts) || empty($parts['host'])) {
            $fail('The '.$attribute.' must include a valid host.');

            return;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The '.$attribute.' must use http or https.');

            return;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTNAMES, true) || str_ends_with($host, '.local')) {
            $fail('The '.$attribute.' points to a blocked internal host.');

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPubliclyRoutable = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($isPubliclyRoutable === false) {
                $fail('The '.$attribute.' points to a private, loopback, or reserved IP address.');
            }
        }
    }
}
