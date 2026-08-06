<?php

namespace Tests\Unit\Services;

use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Tests\TestCase;

class SsrfGuardTest extends TestCase
{
    /** Build a guard whose DNS resolver is a fixed map, forcing pinning on. */
    protected function guard(array $map): SsrfGuard
    {
        return new SsrfGuard(fn (string $host) => $map[$host] ?? []);
    }

    public function test_pins_a_public_host_to_its_validated_ip(): void
    {
        $options = $this->guard(['api.example.com' => ['93.184.216.34']])
            ->pinnedOptions('https://api.example.com/mcp');

        $this->assertSame(
            ['api.example.com:443:93.184.216.34'],
            $options['curl'][CURLOPT_RESOLVE]
        );
    }

    public function test_uses_the_url_port_when_explicit(): void
    {
        $options = $this->guard(['api.example.com' => ['93.184.216.34']])
            ->pinnedOptions('http://api.example.com:8080/x');

        $this->assertSame(['api.example.com:8080:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_pins_all_resolved_public_addresses(): void
    {
        $options = $this->guard(['api.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']])
            ->pinnedOptions('https://api.example.com/');

        $this->assertSame(
            ['api.example.com:443:93.184.216.34,2606:2800:220:1:248:1893:25c8:1946'],
            $options['curl'][CURLOPT_RESOLVE]
        );
    }

    public function test_rejects_rebinding_to_a_private_address(): void
    {
        // The classic rebind: a name that resolves to an internal IP at
        // connection time is refused before any request is made.
        $this->expectException(SsrfException::class);

        $this->guard(['evil.example.com' => ['169.254.169.254']])
            ->pinnedOptions('https://evil.example.com/latest/meta-data/');
    }

    public function test_rejects_when_any_resolved_address_is_private(): void
    {
        $this->expectException(SsrfException::class);

        $this->guard(['mixed.example.com' => ['93.184.216.34', '10.0.0.5']])
            ->pinnedOptions('https://mixed.example.com/');
    }

    public function test_rejects_an_unresolvable_host(): void
    {
        $this->expectException(SsrfException::class);

        $this->guard(['gone.example.com' => []])->pinnedOptions('https://nope.example.com/');
    }

    public function test_public_ip_literal_needs_no_pin(): void
    {
        $this->assertSame([], $this->guard([])->pinnedOptions('https://93.184.216.34/'));
    }

    public function test_private_ip_literal_is_rejected(): void
    {
        $this->expectException(SsrfException::class);

        $this->guard([])->pinnedOptions('http://127.0.0.1/');
    }

    public function test_blocked_internal_names_are_rejected(): void
    {
        $this->expectException(SsrfException::class);

        $this->guard(['metadata.google.internal' => ['169.254.169.254']])
            ->pinnedOptions('http://metadata.google.internal/');
    }

    public function test_pinning_is_skipped_when_resolution_disabled(): void
    {
        // No injected resolver + config off (the test env) => no DNS, no pin,
        // so Http::fake is never disturbed.
        config(['security.ssrf_resolve_dns' => false]);

        $this->assertSame([], (new SsrfGuard)->pinnedOptions('https://anything.example.com/'));
    }
}
