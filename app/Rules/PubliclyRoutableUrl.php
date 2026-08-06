<?php

namespace App\Rules;

use App\Rules\Concerns\ChecksHostRoutability;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Blocks SSRF targets (loopback, private/link-local ranges, cloud metadata
 * hosts) for URLs that this app's proxy/test endpoints will make outbound
 * requests to on the user's behalf.
 *
 * For hostnames, this resolves DNS and rejects the URL if ANY resolved
 * address is non-public. That closes the case of a public hostname that
 * resolves to an internal IP. It does not, on its own, defend against DNS
 * rebinding (a name that resolves to a public IP here and a private one at
 * connection time) — that TOCTOU gap is closed separately at connection time
 * by App\Services\Security\SsrfGuard, which pins the validated IP into the
 * HTTP client so it cannot re-resolve. The HTTP-based outbound clients
 * (proxy, MCP, A2A) apply that pin.
 *
 * DNS resolution is gated by config('security.ssrf_resolve_dns') so it can
 * be disabled in the test environment, where hosts are faked and would not
 * resolve. A resolver may also be injected for deterministic testing.
 */
class PubliclyRoutableUrl implements ValidationRule
{
    use ChecksHostRoutability;

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

        if (($reason = $this->hostRoutabilityError($parts['host'])) !== null) {
            $fail('The '.$attribute.' '.$reason);
        }
    }
}
