<?php

namespace Tests\Unit\Services;

use App\Services\Import\CurlImporter;
use App\Services\Import\ImportException;
use PHPUnit\Framework\TestCase;

class CurlImporterTest extends TestCase
{
    private CurlImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new CurlImporter;
    }

    public function test_it_parses_a_simple_get(): void
    {
        $parsed = $this->importer->parse('curl https://api.example.com/users');

        $this->assertSame('GET', $parsed['method']);
        $this->assertSame('https://api.example.com/users', $parsed['url']);
    }

    public function test_it_parses_the_devtools_copy_as_curl_shape(): void
    {
        // Line continuations, single-quoted JSON containing double quotes, and
        // flags after the URL — the shape people actually paste.
        $command = <<<'CURL'
        curl 'https://api.example.com/v1/orders' \
          -H 'Accept: application/json' \
          -H 'Authorization: Bearer abc.def' \
          --data-raw '{"item":"widget","qty":2}' \
          --compressed
        CURL;

        $parsed = $this->importer->parse($command);

        $this->assertSame('POST', $parsed['method'], 'A body without -X implies POST, as curl does.');
        $this->assertSame('https://api.example.com/v1/orders', $parsed['url']);
        $this->assertSame('Bearer abc.def', $parsed['headers']['Authorization']);
        $this->assertSame('{"item":"widget","qty":2}', $parsed['body']);
        $this->assertSame('application/json', $parsed['headers']['Content-Type']);
    }

    public function test_an_explicit_method_wins(): void
    {
        $parsed = $this->importer->parse("curl -X DELETE 'https://api.example.com/users/3'");

        $this->assertSame('DELETE', $parsed['method']);
    }

    public function test_it_keeps_a_declared_content_type(): void
    {
        $parsed = $this->importer->parse(
            "curl -H 'Content-Type: application/xml' -d '<a/>' https://api.example.com/x"
        );

        $this->assertSame('application/xml', $parsed['headers']['Content-Type']);
    }

    public function test_a_form_body_defaults_to_urlencoded(): void
    {
        $parsed = $this->importer->parse("curl -d 'a=1&b=2' https://api.example.com/x");

        $this->assertSame('application/x-www-form-urlencoded', $parsed['headers']['Content-Type']);
        $this->assertSame('a=1&b=2', $parsed['body']);
    }

    public function test_repeated_data_flags_are_joined_like_curl_does(): void
    {
        $parsed = $this->importer->parse("curl -d 'a=1' -d 'b=2' https://api.example.com/x");

        $this->assertSame('a=1&b=2', $parsed['body']);
    }

    public function test_basic_auth_becomes_an_authorization_header(): void
    {
        $parsed = $this->importer->parse("curl -u alice:s3cret https://api.example.com/x");

        $this->assertSame('Basic '.base64_encode('alice:s3cret'), $parsed['headers']['Authorization']);
    }

    public function test_it_handles_escaped_quotes_inside_a_double_quoted_body(): void
    {
        $parsed = $this->importer->parse('curl -d "{\"a\":\"b\"}" https://api.example.com/x');

        $this->assertSame('{"a":"b"}', $parsed['body']);
    }

    public function test_connection_flags_are_dropped_without_swallowing_the_url(): void
    {
        $parsed = $this->importer->parse(
            'curl -k -L --max-time 30 -A "Spi/1.0" https://api.example.com/users'
        );

        $this->assertSame('https://api.example.com/users', $parsed['url']);
        $this->assertSame('GET', $parsed['method']);
    }

    public function test_multipart_is_reported_rather_than_silently_dropped(): void
    {
        $parsed = $this->importer->parse("curl -F 'file=@a.png' https://api.example.com/upload");

        $this->assertNotEmpty($parsed['warnings']);
        $this->assertStringContainsString('Multipart', $parsed['warnings'][0]);
    }

    public function test_it_rejects_input_that_is_not_curl(): void
    {
        $this->expectException(ImportException::class);
        $this->importer->parse('wget https://api.example.com');
    }

    public function test_it_rejects_a_command_with_no_url(): void
    {
        $this->expectException(ImportException::class);
        $this->importer->parse('curl -X POST -H "Accept: */*"');
    }

    public function test_it_preserves_template_variables(): void
    {
        $parsed = $this->importer->parse("curl 'https://{{base_url}}/users' -H 'Authorization: Bearer {{token}}'");

        $this->assertSame('https://{{base_url}}/users', $parsed['url']);
        $this->assertSame('Bearer {{token}}', $parsed['headers']['Authorization']);
    }
}
