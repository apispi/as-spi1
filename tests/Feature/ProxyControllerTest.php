<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxyControllerTest extends TestCase
{
    public function test_forwards_the_request_and_returns_the_response(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->postJson('/api/proxy', [
            'url' => 'https://api.example.com/data',
            'method' => 'GET',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 200);
    }

    public function test_rejects_ssrf_targets(): void
    {
        $response = $this->postJson('/api/proxy', [
            'url' => 'http://169.254.169.254/latest/meta-data/',
            'method' => 'GET',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_rejects_loopback_targets(): void
    {
        $response = $this->postJson('/api/proxy', [
            'url' => 'http://localhost/admin',
            'method' => 'GET',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['url']);
    }
}
