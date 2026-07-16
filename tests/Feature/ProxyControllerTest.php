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

    public function test_accepts_a_structured_json_body_and_forwards_it_encoded(): void
    {
        // The homepage testers (MCP, A2A, GraphQL, webhook, REST POST) build a
        // decoded object rather than a string.
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $response = $this->postJson('/api/proxy', [
            'url' => 'https://api.example.com/rpc',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1],
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 200);

        Http::assertSent(function ($request) {
            return $request->body() === '{"jsonrpc":"2.0","method":"tools\/list","id":1}';
        });
    }

    public function test_accepts_a_raw_string_body(): void
    {
        // The dashboard passes the editor contents through verbatim.
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $response = $this->postJson('/api/proxy', [
            'url' => 'https://api.example.com/rpc',
            'method' => 'POST',
            'body' => '{"hello":"world"}',
        ]);

        $response->assertStatus(200);

        Http::assertSent(fn ($request) => $request->body() === '{"hello":"world"}');
    }

    public function test_rejects_loopback_targets(): void
    {
        $response = $this->postJson('/api/proxy', [
            'url' => 'http://localhost/admin',
            'method' => 'GET',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_does_not_follow_redirects_to_internal_hosts(): void
    {
        // A public URL that redirects to a cloud-metadata address. With
        // redirect-following disabled, the proxy returns the 302 itself and
        // never fetches the internal target.
        Http::fake([
            'evil.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('SECRET', 200),
        ]);

        $response = $this->postJson('/api/proxy', [
            'url' => 'https://evil.example.com/redirect',
            'method' => 'GET',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 302);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }
}
