<?php

namespace App\Rules\Concerns;

/**
 * Shared SSRF host-routability checks used by both the URL rule (which parses a
 * host out of a URL) and the bare-host rule (used by the MQTT/AMQP/gRPC testers,
 * where the target is a host:port rather than an http(s) URL).
 *
 * See App\Rules\PubliclyRoutableUrl for the DNS-rebinding caveat: resolving here
 * closes the "public name → private IP" case but not a name that resolves
 * differently at connection time.
 *
 * A `$resolver` property (nullable callable) on the using class enables
 * deterministic testing and forces DNS resolution on.
 */
trait ChecksHostRoutability
{
    /**
     * Hostnames blocked outright regardless of resolution.
     *
     * @return array<int, string>
     */
    protected function blockedHostnames(): array
    {
        return ['localhost', 'metadata.google.internal'];
    }

    /**
     * Returns a human-readable reason the host is not publicly routable, or null
     * when the host is safe to connect to. The reason is a sentence fragment so
     * callers can prefix it with the attribute name.
     */
    protected function hostRoutabilityError(string $host): ?string
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '') {
            return 'must include a valid host.';
        }

        if (in_array($host, $this->blockedHostnames(), true) || str_ends_with($host, '.local')) {
            return 'points to a blocked internal host.';
        }

        // Host is an IP literal: validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host)
                ? null
                : 'points to a private, loopback, or reserved IP address.';
        }

        if (! $this->shouldResolve()) {
            return null;
        }

        // Host is a name: resolve it and reject if anything it points to is
        // non-public. A resolution failure is treated as unroutable.
        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return 'host could not be resolved.';
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                return 'resolves to a private, loopback, or reserved IP address.';
            }
        }

        return null;
    }

    protected function shouldResolve(): bool
    {
        return ($this->resolver ?? null) !== null || (bool) config('security.ssrf_resolve_dns', true);
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
        $resolver = $this->resolver ?? null;

        if ($resolver !== null) {
            return array_values(array_filter($resolver($host)));
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

        // Fall back to gethostbyname for environments where dns_get_record is
        // restricted; it only returns IPv4 but is widely available.
        if ($addresses === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $addresses[] = $resolved;
            }
        }

        return $addresses;
    }
}
