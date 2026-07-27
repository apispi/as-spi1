<?php

namespace Tests\Unit\Rules;

use App\Rules\PubliclyRoutableHost;
use Tests\TestCase;

class PubliclyRoutableHostTest extends TestCase
{
    protected function fails(mixed $host, ?callable $resolver = null): bool
    {
        $failed = false;

        (new PubliclyRoutableHost($resolver))->validate('host', $host, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_allows_ordinary_public_hosts(): void
    {
        $this->assertFalse($this->fails('broker.example.com'));
        $this->assertFalse($this->fails('8.8.8.8'));
    }

    public function test_blocks_loopback_and_private_ips(): void
    {
        $this->assertTrue($this->fails('127.0.0.1'));
        $this->assertTrue($this->fails('::1'));
        $this->assertTrue($this->fails('10.0.0.5'));
        $this->assertTrue($this->fails('192.168.1.1'));
        $this->assertTrue($this->fails('169.254.169.254'));
    }

    public function test_blocks_localhost_and_dot_local(): void
    {
        $this->assertTrue($this->fails('localhost'));
        $this->assertTrue($this->fails('broker.local'));
        $this->assertTrue($this->fails('metadata.google.internal'));
    }

    public function test_blocks_empty_or_non_string(): void
    {
        $this->assertTrue($this->fails(''));
        $this->assertTrue($this->fails(null));
    }

    public function test_blocks_host_resolving_to_private_ip(): void
    {
        $this->assertTrue($this->fails('sneaky.example.com', fn () => ['10.1.2.3']));
    }

    public function test_allows_host_resolving_to_public_ips(): void
    {
        $this->assertFalse($this->fails('legit.example.com', fn () => ['93.184.216.34']));
    }
}
