<?php

namespace Tests\Unit\Services;

use App\Services\Auth\RequestAuthenticator;
use PHPUnit\Framework\TestCase;

class RequestAuthenticatorTest extends TestCase
{
    private RequestAuthenticator $auth;

    private const URL = 'https://api.example.com/users';

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new RequestAuthenticator;
    }

    private function apply(?array $config, array $headers = [], ?string $url = null): array
    {
        return $this->auth->apply($config, $headers, $url ?? self::URL);
    }

    public function test_no_config_leaves_the_request_untouched(): void
    {
        $result = $this->apply(null, ['Accept' => 'application/json']);

        $this->assertSame(['Accept' => 'application/json'], $result['headers']);
        $this->assertSame(self::URL, $result['url']);
    }

    public function test_the_none_scheme_is_a_no_op(): void
    {
        $result = $this->apply(['scheme' => 'none', 'token' => 'ignored']);

        $this->assertSame([], $result['headers']);
    }

    public function test_an_unknown_scheme_is_ignored_rather_than_guessed_at(): void
    {
        $result = $this->apply(['scheme' => 'hawk', 'token' => 'x']);

        $this->assertSame([], $result['headers']);
    }

    public function test_bearer_sets_the_authorization_header(): void
    {
        $result = $this->apply(['scheme' => 'bearer', 'token' => 'abc123']);

        $this->assertSame(['Authorization' => 'Bearer abc123'], $result['headers']);
    }

    public function test_bearer_does_not_double_the_scheme_when_the_token_carries_it(): void
    {
        $result = $this->apply(['scheme' => 'bearer', 'token' => 'Bearer abc123']);

        $this->assertSame('Bearer abc123', $result['headers']['Authorization']);
    }

    public function test_an_empty_bearer_token_adds_no_header(): void
    {
        $result = $this->apply(['scheme' => 'bearer', 'token' => '   ']);

        $this->assertSame([], $result['headers']);
    }

    public function test_basic_encodes_the_credentials(): void
    {
        $result = $this->apply(['scheme' => 'basic', 'username' => 'ada', 'password' => 's3cret']);

        $this->assertSame('Basic '.base64_encode('ada:s3cret'), $result['headers']['Authorization']);
    }

    public function test_basic_with_neither_field_adds_no_header(): void
    {
        $this->assertSame([], $this->apply(['scheme' => 'basic', 'username' => '', 'password' => ''])['headers']);
    }

    public function test_an_api_key_can_go_in_a_header(): void
    {
        $result = $this->apply(['scheme' => 'api_key', 'key' => 'X-Api-Key', 'value' => 'k-1', 'in' => 'header']);

        $this->assertSame(['X-Api-Key' => 'k-1'], $result['headers']);
        $this->assertSame(self::URL, $result['url']);
    }

    public function test_an_api_key_can_go_in_the_query_string(): void
    {
        $result = $this->apply(['scheme' => 'api_key', 'key' => 'api_key', 'value' => 'k 1', 'in' => 'query']);

        $this->assertSame([], $result['headers']);
        $this->assertSame('https://api.example.com/users?api_key=k+1', $result['url']);
    }

    public function test_a_query_api_key_preserves_existing_parameters_and_fragment(): void
    {
        $result = $this->apply(
            ['scheme' => 'api_key', 'key' => 'token', 'value' => 'abc', 'in' => 'query'],
            [],
            'https://api.example.com:8443/v1/users?page=2#top'
        );

        $this->assertStringContainsString('page=2', $result['url']);
        $this->assertStringContainsString('token=abc', $result['url']);
        $this->assertStringStartsWith('https://api.example.com:8443/v1/users?', $result['url']);
        $this->assertStringEndsWith('#top', $result['url']);
    }

    public function test_an_api_key_with_no_name_is_ignored(): void
    {
        $result = $this->apply(['scheme' => 'api_key', 'key' => '', 'value' => 'k-1', 'in' => 'header']);

        $this->assertSame([], $result['headers']);
        $this->assertSame(self::URL, $result['url']);
    }

    public function test_an_unparseable_url_is_returned_untouched(): void
    {
        // Downstream URL validation gives a better message than anything the
        // authenticator could invent, so it must not mangle the value.
        $result = $this->apply(
            ['scheme' => 'api_key', 'key' => 'token', 'value' => 'abc', 'in' => 'query'],
            [],
            'not a url'
        );

        $this->assertSame('not a url', $result['url']);
    }

    public function test_auth_replaces_a_hand_typed_header_of_the_same_name_case_insensitively(): void
    {
        $result = $this->apply(
            ['scheme' => 'bearer', 'token' => 'fresh'],
            ['authorization' => 'Bearer stale', 'Accept' => 'application/json']
        );

        $this->assertSame('Bearer fresh', $result['headers']['Authorization']);
        $this->assertArrayNotHasKey('authorization', $result['headers']);
        $this->assertSame('application/json', $result['headers']['Accept']);
    }
}
