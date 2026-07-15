<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Blocks SSRF targets (loopback, private/link-local ranges, cloud metadata
 * hosts) for URLs that this app's proxy/test endpoints will make outbound
 * requests to on the user's behalf.
 *
 * For hostnames, this resolves DNS and rejects the URL if ANY resolved
 * address is non-public. That closes the case of a public hostname that
 * resolves to an internal IP, but it is still not a full defence against
 * DNS rebinding, where the name resolves to a public IP here and a private
 * one at connection time. Fully closing that requires pinning the validated
 * IP into the HTTP client's connection, which the outbound clients here do
 * not yet do.
 *
 * DNS resolution is gated by config('security.ssrf_resolve_dns') so it can
 * be disabled in the test environment, where hosts are faked and would not
 * resolve. A resolver may also be injected for deterministic testing.
 */
class PubliclyRoutableUrl implements ValidationRule
{
    protected const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
    ];

    /**
     * @param  (callable(string): array<int, string>)|null  $resolver
     *   Optional host resolver; when provided, DNS is always checked.
     */
    public function __construct(protected $resolver = null)
    {
    }

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

        // Host is an IP literal: validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                $fail('The '.$attribute.' points to a private, loopback, or reserved IP address.');
            }

            return;
        }

        if (! $this->shouldResolve()) {
            return;
        }

        // Host is a name: resolve it and reject if anything it points to is
        // non-public. A resolution failure is treated as unroutable.
        $addresses = $this->resolve($host);

        if ($addresses === []) {
            $fail('The '.$attribute.' host could not be resolved.');

            return;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                $fail('The '.$attribute.' resolves to a private, loopback, or reserved IP address.');

                return;
            }
        }
    }

    protected function shouldResolve(): bool
    {
        return $this->resolver !== null || (bool) config('security.ssrf_resolve_dns', true);
    }

    protected function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return array<int, string> Resolved IPv4 and IPv6 addresses.
     */
    protected function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(array_filter(($this->resolver)($host)));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        // Fall back to gethostbyname for environments where dns_get_record
        // is restricted; it only returns IPv4 but is widely available.
        if ($addresses === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $addresses[] = $resolved;
            }
        }

        return $addresses;
    }
}
