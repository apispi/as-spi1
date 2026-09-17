<?php

namespace Tests\Unit\Services;

use App\Services\Variables\DynamicVariables;
use App\Services\Variables\VariableResolver;
use PHPUnit\Framework\TestCase;

class DynamicVariablesTest extends TestCase
{
    private DynamicVariables $dynamic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dynamic = new DynamicVariables;
    }

    public function test_it_recognises_known_tokens_and_rejects_unknown_ones(): void
    {
        $this->assertTrue($this->dynamic->has('$uuid'));
        $this->assertTrue($this->dynamic->has('$timestamp'));
        $this->assertFalse($this->dynamic->has('$notAThing'));
        $this->assertFalse($this->dynamic->has('$'));
    }

    public function test_uuid_is_a_valid_v4_uuid(): void
    {
        $value = $this->dynamic->resolve('$uuid');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    public function test_timestamp_and_iso_timestamp_have_the_expected_shape(): void
    {
        $this->assertMatchesRegularExpression('/^\d{9,}$/', $this->dynamic->resolve('$timestamp'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $this->dynamic->resolve('$isoTimestamp'));
    }

    public function test_random_email_looks_like_an_email(): void
    {
        $this->assertMatchesRegularExpression('/^[^@\s]+@example\.com$/', $this->dynamic->resolve('$randomEmail'));
    }

    public function test_random_int_stays_within_range(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $n = (int) $this->dynamic->resolve('$randomInt');
            $this->assertGreaterThanOrEqual(0, $n);
            $this->assertLessThanOrEqual(1000, $n);
        }
    }

    public function test_unknown_token_resolves_to_null(): void
    {
        $this->assertNull($this->dynamic->resolve('$nope'));
    }

    public function test_catalogue_lists_tokens_with_examples(): void
    {
        $catalogue = $this->dynamic->catalogue();
        $this->assertNotEmpty($catalogue);
        foreach ($catalogue as $entry) {
            $this->assertStringStartsWith('$', $entry['token']);
            $this->assertNotSame('', $entry['description']);
            $this->assertNotSame('', $entry['example']);
        }
    }

    public function test_resolver_substitutes_dynamic_variables_and_marks_them_used(): void
    {
        $resolver = new VariableResolver;

        $out = $resolver->resolve('id={{$uuid}}', []);

        $this->assertMatchesRegularExpression('/^id=[0-9a-f-]{36}$/', $out);
        $this->assertContains('$uuid', $resolver->used());
        $this->assertSame([], $resolver->unresolved());
    }

    public function test_each_use_of_a_dynamic_variable_is_independent(): void
    {
        $resolver = new VariableResolver;

        $out = $resolver->resolve('{{$uuid}}|{{$uuid}}', []);
        [$a, $b] = explode('|', $out);

        $this->assertNotSame($a, $b, 'Two {{$uuid}} should produce different ids.');
    }

    public function test_an_environment_variable_overrides_a_dynamic_one(): void
    {
        $resolver = new VariableResolver;

        // A test can pin a computed variable to a fixed value for determinism.
        $out = $resolver->resolve('{{$timestamp}}', ['$timestamp' => 'FIXED']);

        $this->assertSame('FIXED', $out);
    }

    public function test_an_unknown_dollar_token_is_reported_unresolved(): void
    {
        $resolver = new VariableResolver;

        $out = $resolver->resolve('{{$madeUp}}', []);

        $this->assertSame('{{$madeUp}}', $out);
        $this->assertContains('$madeUp', $resolver->unresolved());
    }

    public function test_plain_variables_still_resolve_as_before(): void
    {
        $resolver = new VariableResolver;

        $out = $resolver->resolve('{{base}}/users', ['base' => 'https://api.test']);

        $this->assertSame('https://api.test/users', $out);
    }
}
