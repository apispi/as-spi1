<?php

namespace Tests\Unit\Rules;

use App\Rules\PubliclyRoutableUrl;
use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Tests\TestCase;

/**
 * Noncanonical spellings of an internal host must not walk past the SSRF
 * checks. Prompted by GHSA-v5mv-p594-2x33 (Guzzle: noncanonical host can
 * bypass host-based checks); the app does its own host parsing, so the
 * equivalent bypasses are pinned here directly.
 */
class NoncanonicalHostTest extends TestCase
{
    private function blocked(string $url, ?callable $resolver = null): bool
    {
        $failed = false;

        (new PubliclyRoutableUrl($resolver))->validate('url', $url, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    /**
     * With resolution off, the blocklist and IP-literal check are the only
     * defence — exactly where a trailing dot used to slip through.
     */
    public function test_a_trailing_dot_does_not_bypass_the_checks_without_dns(): void
    {
        config(['security.ssrf_resolve_dns' => false]);

        $this->assertTrue($this->blocked('http://localhost./'), 'localhost. reached the blocklist uncanonicalised.');
        $this->assertTrue($this->blocked('http://127.0.0.1./'), 'A dotted IP literal was not recognised as an IP.');
        $this->assertTrue($this->blocked('http://LocalHost./'));
        $this->assertTrue($this->blocked('http://127.0.0.1.../'));
    }

    public function test_case_and_bracket_forms_are_canonicalised(): void
    {
        config(['security.ssrf_resolve_dns' => false]);

        $this->assertTrue($this->blocked('http://LOCALHOST/'));
        $this->assertTrue($this->blocked('http://[::1]/'));
        $this->assertTrue($this->blocked('http://169.254.169.254/'));
    }

    /**
     * The host is what follows the last "@" — a public-looking userinfo prefix
     * must not decide the verdict.
     */
    public function test_userinfo_does_not_disguise_an_internal_host(): void
    {
        config(['security.ssrf_resolve_dns' => false]);

        $this->assertTrue($this->blocked('http://api.example.com@127.0.0.1/'));
        $this->assertTrue($this->blocked('http://user:pass@localhost/'));
    }

    /**
     * Shorthand and numeric IP spellings are not IP literals to PHP, so they
     * fall through to resolution — which must then reject them.
     */
    public function test_numeric_and_shorthand_addresses_are_rejected_via_resolution(): void
    {
        $resolver = fn (string $host) => in_array($host, ['2130706433', '0177.0.0.1', '127.1'], true)
            ? ['127.0.0.1']
            : [];

        $this->assertTrue($this->blocked('http://2130706433/', $resolver));
        $this->assertTrue($this->blocked('http://0177.0.0.1/', $resolver));
        $this->assertTrue($this->blocked('http://127.1/', $resolver));
    }

    public function test_a_genuinely_public_host_still_passes(): void
    {
        $resolver = fn () => ['93.184.216.34'];

        $this->assertFalse($this->blocked('https://api.example.com/v1/users', $resolver));
        $this->assertFalse($this->blocked('https://api.example.com./v1/users', $resolver),
            'A trailing dot on a public host is legal and must not be rejected.');
    }

    public function test_the_guard_blocks_a_noncanonical_internal_host(): void
    {
        $guard = new SsrfGuard(fn () => ['93.184.216.34']);

        $this->expectException(SsrfException::class);
        $guard->pinnedOptions('http://127.0.0.1./');
    }

    /**
     * The pin must key off the host cURL reads from the URL. Canonicalising
     * the key would make CURLOPT_RESOLVE silently fail to match, and cURL
     * would resolve the name again — reopening the rebinding gap the guard
     * exists to close.
     */
    public function test_the_pin_key_matches_the_host_curl_will_use(): void
    {
        $guard = new SsrfGuard(fn () => ['93.184.216.34']);

        $options = $guard->pinnedOptions('https://api.example.com./v1');

        $this->assertSame(
            ['api.example.com.:443:93.184.216.34'],
            $options['curl'][CURLOPT_RESOLVE]
        );
    }

    public function test_the_pin_is_built_for_an_ordinary_host(): void
    {
        $guard = new SsrfGuard(fn () => ['93.184.216.34', '93.184.216.35']);

        $options = $guard->pinnedOptions('https://api.example.com/v1');

        $this->assertSame(
            ['api.example.com:443:93.184.216.34,93.184.216.35'],
            $options['curl'][CURLOPT_RESOLVE]
        );
    }
}
