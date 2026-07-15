<?php

namespace Tests\Unit\Rules;

use App\Rules\PubliclyRoutableUrl;
use Tests\TestCase;

class PubliclyRoutableUrlTest extends TestCase
{
    protected function fails(string $url): bool
    {
        $failed = false;

        (new PubliclyRoutableUrl)->validate('url', $url, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_allows_ordinary_public_urls(): void
    {
        $this->assertFalse($this->fails('https://api.example.com/mcp'));
        $this->assertFalse($this->fails('http://8.8.8.8/'));
    }

    public function test_blocks_loopback_addresses(): void
    {
        $this->assertTrue($this->fails('http://127.0.0.1/'));
        $this->assertTrue($this->fails('http://[::1]/'));
    }

    public function test_blocks_private_ranges(): void
    {
        $this->assertTrue($this->fails('http://10.0.0.5/'));
        $this->assertTrue($this->fails('http://192.168.1.1/'));
        $this->assertTrue($this->fails('http://172.16.0.1/'));
    }

    public function test_blocks_link_local_and_cloud_metadata(): void
    {
        $this->assertTrue($this->fails('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_blocks_localhost_and_dot_local_hostnames(): void
    {
        $this->assertTrue($this->fails('http://localhost/'));
        $this->assertTrue($this->fails('http://LOCALHOST/'));
        $this->assertTrue($this->fails('http://printer.local/'));
        $this->assertTrue($this->fails('http://metadata.google.internal/'));
    }

    public function test_blocks_non_http_schemes(): void
    {
        $this->assertTrue($this->fails('ftp://example.com/'));
        $this->assertTrue($this->fails('file:///etc/passwd'));
    }
}
