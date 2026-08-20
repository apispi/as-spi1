<?php

namespace Tests\Unit\Services;

use App\Services\Assertions\AssertionEvaluator;
use PHPUnit\Framework\TestCase;

class AssertionEvaluatorTest extends TestCase
{
    private AssertionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new AssertionEvaluator;
    }

    private function response(array $overrides = []): array
    {
        return array_merge([
            'status' => 200,
            'time_ms' => 142,
            'headers' => ['Content-Type' => ['application/json'], 'X-Request-Id' => 'abc-123'],
            'body' => json_encode([
                'ok' => true,
                'count' => 3,
                'name' => 'Ada Lovelace',
                'nothing' => null,
                'data' => ['items' => [['id' => 1], ['id' => 2]]],
            ]),
        ], $overrides);
    }

    private function check(array $assertions, ?array $response = null): array
    {
        return $this->evaluator->evaluate($assertions, $response ?? $this->response());
    }

    public function test_it_asserts_on_status_and_latency(): void
    {
        $result = $this->check([
            ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
            ['path' => 'time_ms', 'operator' => 'less_than', 'expected' => 1000],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame(2, $result['passed_count']);
    }

    public function test_a_string_expected_value_matches_a_numeric_status(): void
    {
        // Values arriving from a form or JSON body are strings; "200" must
        // still match an integer 200 or every AI-generated assertion fails.
        $result = $this->check([['path' => 'status', 'operator' => 'equals', 'expected' => '200']]);

        $this->assertTrue($result['passed']);
    }

    public function test_it_walks_json_paths_in_both_notations(): void
    {
        $result = $this->check([
            ['path' => 'data.items.0.id', 'operator' => 'equals', 'expected' => 1],
            ['path' => '$.data.items[1].id', 'operator' => 'equals', 'expected' => 2],
        ]);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_the_root_path_addresses_the_whole_body(): void
    {
        $response = $this->response(['body' => json_encode([['id' => 1], ['id' => 2]])]);

        $result = $this->check([
            ['path' => '$', 'operator' => 'is_type', 'expected' => 'array'],
            ['path' => '$', 'operator' => 'has_length', 'expected' => 2],
        ], $response);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_it_asserts_on_headers_case_insensitively(): void
    {
        $result = $this->check([
            ['path' => 'header.content-type', 'operator' => 'contains', 'expected' => 'application/json'],
            ['path' => 'header.X-Request-Id', 'operator' => 'equals', 'expected' => 'abc-123'],
        ]);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_exists_distinguishes_a_missing_key_from_a_null_value(): void
    {
        $result = $this->check([
            ['path' => 'nothing', 'operator' => 'exists'],
            ['path' => 'absent', 'operator' => 'not_exists'],
            ['path' => 'nothing', 'operator' => 'is_type', 'expected' => 'null'],
        ]);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_is_type_separates_json_arrays_from_objects(): void
    {
        $result = $this->check([
            ['path' => 'data.items', 'operator' => 'is_type', 'expected' => 'array'],
            ['path' => 'data', 'operator' => 'is_type', 'expected' => 'object'],
            ['path' => 'ok', 'operator' => 'is_type', 'expected' => 'boolean'],
            ['path' => 'count', 'operator' => 'is_type', 'expected' => 'number'],
            ['path' => 'name', 'operator' => 'is_type', 'expected' => 'string'],
        ]);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_a_numeric_string_is_not_a_number(): void
    {
        $response = $this->response(['body' => json_encode(['n' => '42'])]);

        $result = $this->check([['path' => 'n', 'operator' => 'is_type', 'expected' => 'number']], $response);

        $this->assertFalse($result['passed']);
    }

    public function test_has_length_counts_strings_and_arrays(): void
    {
        $result = $this->check([
            ['path' => 'data.items', 'operator' => 'has_length', 'expected' => 2],
            ['path' => 'name', 'operator' => 'has_length', 'expected' => 12],
        ]);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_matches_accepts_bare_and_delimited_patterns(): void
    {
        $result = $this->check([
            ['path' => 'name', 'operator' => 'matches', 'expected' => '^Ada'],
            ['path' => 'name', 'operator' => 'matches', 'expected' => '/lovelace$/i'],
        ]);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_an_invalid_regex_fails_with_a_reason_rather_than_throwing(): void
    {
        $result = $this->check([['path' => 'name', 'operator' => 'matches', 'expected' => '/[unclosed/']]);

        $this->assertFalse($result['passed']);
        $this->assertSame('Invalid regular expression.', $result['results'][0]['error']);
    }

    public function test_unknown_operators_and_missing_paths_fail_with_a_reason(): void
    {
        $result = $this->check([
            ['path' => 'name', 'operator' => 'includes', 'expected' => 'Ada'],
            ['path' => 'absent.deep', 'operator' => 'equals', 'expected' => 'x'],
            ['path' => '', 'operator' => 'equals', 'expected' => 'x'],
            ['path' => 'status', 'operator' => 'equals', 'expected' => null],
        ]);

        $this->assertSame(0, $result['passed_count']);
        $this->assertSame('Unknown operator: includes', $result['results'][0]['error']);
        $this->assertSame('No value at that path.', $result['results'][1]['error']);
        $this->assertSame('Assertion has no path.', $result['results'][2]['error']);
        $this->assertStringContainsString('needs an expected value', $result['results'][3]['error']);
    }

    public function test_comparing_a_non_numeric_value_fails_rather_than_coercing(): void
    {
        // "Ada Lovelace" > 5 must not silently become 0 > 5.
        $result = $this->check([['path' => 'name', 'operator' => 'greater_than', 'expected' => 5]]);

        $this->assertFalse($result['passed']);
        $this->assertSame('Value is not numeric.', $result['results'][0]['error']);
    }

    public function test_a_non_json_body_can_still_be_asserted_on_as_text(): void
    {
        $response = $this->response(['body' => 'plain text response']);

        $result = $this->check([
            ['path' => 'body', 'operator' => 'contains', 'expected' => 'plain text'],
            ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
        ], $response);

        $this->assertTrue($result['passed'], json_encode($result['results']));
    }

    public function test_it_reports_a_mixed_run_accurately(): void
    {
        $result = $this->check([
            ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
            ['path' => 'count', 'operator' => 'equals', 'expected' => 99],
        ]);

        $this->assertFalse($result['passed']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['passed_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertSame(3, $result['results'][1]['actual']);
    }
}
