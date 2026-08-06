<?php

namespace App\Services\Security;

use App\Rules\Concerns\ChecksHostRoutability;

/**
 * Connection-time SSRF defence that closes the DNS-rebinding gap left by the
 * PubliclyRoutableUrl/Host validation rules.
 *
 * The rules resolve a host and reject private results, but resolution happens
 * at *validation* time; a hostname an attacker controls can return a public IP
 * then and a private one when the HTTP client later connects (TOCTOU). This
 * guard resolves the host itself, validates every address, and pins the exact
 * validated IP(s) into cURL via CURLOPT_RESOLVE — so the client connects to
 * what we checked, with no second resolution. The hostname is preserved for TLS
 * SNI and certificate validation.
 *
 * Pinning is skipped when DNS resolution is disabled (the test environment,
 * where hosts are faked) so it never interferes with Http::fake, and for IP
 * literals, which cannot rebind. Resolve once per client instance and reuse the
 * result, so a host can't resolve differently between calls in one session.
 */
class SsrfGuard
{
    use ChecksHostRoutability;

    /**
     * @param  (callable(string): array<int, string>)|null  $resolver
     *   Optional host resolver; when provided, pinning is always attempted
     *   (used for deterministic tests). Mirrors the validation rules.
     */
    public function __construct(protected $resolver = null)
    {
    }

    /**
     * Build the HTTP client options that pin $url's host to its validated
     * address(es). Returns an empty array when pinning does not apply (DNS
     * resolution disabled, or the host is a public IP literal). Throws
     * SsrfException when the host is unsafe or unresolvable.
     *
     * @return array<string, mixed>
     */
    public function pinnedOptions(string $url): array
    {
        // Pinning off (e.g. test env): defer entirely to request-time
        // validation and don't touch DNS.
        if (! $this->shouldResolve()) {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new SsrfException('Outbound URL has no host to validate.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);

        if (in_array($host, $this->blockedHostnames(), true) || str_ends_with($host, '.local')) {
            throw new SsrfException('Refusing to connect to a blocked internal host.');
        }

        // IP literals cannot rebind; validate directly and let cURL connect as
        // usual (no CURLOPT_RESOLVE entry needed).
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw new SsrfException('Refusing to connect to a private, loopback, or reserved IP.');
            }

            return [];
        }

        $addresses = $this->resolve($host);
        if ($addresses === []) {
            throw new SsrfException('Host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw new SsrfException('Host resolves to a private, loopback, or reserved IP address.');
            }
        }

        // Pin every validated address for this host:port. cURL will connect to
        // one of these and never re-resolve the name.
        $entry = $host.':'.$port.':'.implode(',', $addresses);

        return ['curl' => [CURLOPT_RESOLVE => [$entry]]];
    }
}
