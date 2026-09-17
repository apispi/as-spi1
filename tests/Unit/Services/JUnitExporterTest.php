<?php

namespace Tests\Unit\Services;

use App\Services\Reports\JUnitExporter;
use PHPUnit\Framework\TestCase;

class JUnitExporterTest extends TestCase
{
    private JUnitExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new JUnitExporter;
    }

    private function runResult(array $steps, array $overrides = []): array
    {
        return array_merge([
            'passed' => ! collect($steps)->contains(fn ($s) => ! ($s['passed'] ?? false)),
            'collection' => ['id' => 1, 'name' => 'Checkout suite'],
            'environment' => ['id' => 2, 'name' => 'Staging'],
            'time_ms' => 1234,
            'steps' => $steps,
        ], $overrides);
    }

    private function step(array $overrides = []): array
    {
        return array_merge([
            'index' => 0,
            'name' => 'Fetch user',
            'method' => 'GET',
            'url' => 'https://api.example.com/users/1',
            'status' => 200,
            'time_ms' => 120,
            'error' => null,
            'unresolved' => [],
            'assertions' => null,
            'passed' => true,
            'skipped' => false,
        ], $overrides);
    }

    private function parse(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($parsed, 'The exporter produced XML that does not parse.');

        return $parsed;
    }

    public function test_it_supports_only_run_shaped_reports(): void
    {
        $this->assertTrue($this->exporter->supports('collection_run'));
        $this->assertTrue($this->exporter->supports('dataset_run'));
        $this->assertFalse($this->exporter->supports('security'));
        $this->assertFalse($this->exporter->supports('api_diff'));
    }

    public function test_a_passing_run_produces_a_suite_with_no_failures(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step(),
            $this->step(['index' => 1, 'name' => 'Create order', 'method' => 'POST']),
        ]));

        $doc = $this->parse($xml);
        $suite = $doc->testsuite;

        $this->assertSame('2', (string) $suite['tests']);
        $this->assertSame('0', (string) $suite['failures']);
        $this->assertSame('0', (string) $suite['skipped']);
        $this->assertSame('Checkout suite (Staging)', (string) $suite['name']);
        $this->assertSame('1.234', (string) $suite['time']);
        $this->assertCount(2, $suite->testcase);
        $this->assertSame('GET Fetch user', (string) $suite->testcase[0]['name']);
        $this->assertSame('0.120', (string) $suite->testcase[0]['time']);
    }

    public function test_a_failed_step_becomes_a_failure_carrying_the_assertion_detail(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step([
                'passed' => false,
                'status' => 500,
                'assertions' => ['passed' => false, 'results' => [
                    ['path' => 'status', 'operator' => 'equals', 'expected' => '200', 'actual' => 500, 'passed' => false, 'description' => 'Documented success status'],
                    ['path' => '$.id', 'operator' => 'exists', 'expected' => null, 'actual' => null, 'passed' => true],
                ]],
            ]),
        ]));

        $doc = $this->parse($xml);
        $suite = $doc->testsuite;

        $this->assertSame('1', (string) $suite['failures']);

        $failure = $suite->testcase[0]->failure;
        $this->assertSame('1 assertion(s) failed', (string) $failure['message']);

        $detail = (string) $failure;
        $this->assertStringContainsString('Status: 500', $detail);
        $this->assertStringContainsString('status equals 200 — actual: 500', $detail);
        $this->assertStringContainsString('Documented success status', $detail);
        // The passing assertion is not noise in the failure body.
        $this->assertStringNotContainsString('$.id', $detail);
    }

    public function test_a_transport_error_is_used_as_the_failure_message(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step(['passed' => false, 'status' => null, 'error' => 'Connection timed out']),
        ]));

        $failure = $this->parse($xml)->testsuite->testcase[0]->failure;
        $this->assertSame('Connection timed out', (string) $failure['message']);
    }

    public function test_a_skipped_step_is_marked_skipped_not_failed(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step(['passed' => false, 'error' => 'Boom']),
            $this->step(['index' => 1, 'name' => 'Later step', 'passed' => false, 'skipped' => true, 'error' => 'Skipped after an earlier step failed.']),
        ]));

        $doc = $this->parse($xml);
        $suite = $doc->testsuite;

        $this->assertSame('2', (string) $suite['tests']);
        $this->assertSame('1', (string) $suite['failures'], 'The skipped step must not count as a failure.');
        $this->assertSame('1', (string) $suite['skipped']);
        $this->assertNotNull($suite->testcase[1]->skipped);
    }

    public function test_unresolved_variables_are_reported_in_the_failure_body(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step(['passed' => false, 'unresolved' => ['token', 'base_url']]),
        ]));

        $detail = (string) $this->parse($xml)->testsuite->testcase[0]->failure;
        $this->assertStringContainsString('Unresolved variables: token, base_url', $detail);
    }

    public function test_a_step_with_no_name_falls_back_to_its_index(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step(['index' => 3, 'name' => '', 'method' => '']),
        ]));

        $this->assertSame('Step 4', (string) $this->parse($xml)->testsuite->testcase[0]['name']);
    }

    public function test_names_and_details_containing_xml_are_escaped_safely(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([
            $this->step([
                'name' => 'Search <a & b> "quoted"',
                'passed' => false,
                'error' => 'Bad ]]> payload & <tag>',
            ]),
        ]));

        // The document must still parse, and the values round-trip intact.
        $case = $this->parse($xml)->testsuite->testcase[0];
        $this->assertSame('GET Search <a & b> "quoted"', (string) $case['name']);
        $this->assertSame('Bad ]]> payload & <tag>', (string) $case->failure['message']);
    }

    public function test_a_dataset_run_becomes_one_case_per_row(): void
    {
        $xml = $this->exporter->render('dataset_run', [
            'passed' => false,
            'collection' => ['id' => 1, 'name' => 'Signup matrix'],
            'environment' => ['id' => 2, 'name' => 'Staging'],
            'rows' => 2,
            'passed_rows' => 1,
            'failed_rows' => 1,
            'iterations' => [
                ['row' => 1, 'variables' => ['email'], 'passed' => true, 'passed_count' => 2, 'total' => 2, 'first_failure' => null],
                ['row' => 2, 'variables' => ['email'], 'passed' => false, 'passed_count' => 1, 'total' => 2, 'first_failure' => 'Create account'],
            ],
        ]);

        $doc = $this->parse($xml);
        $suite = $doc->testsuite;

        $this->assertSame('Signup matrix (Staging) — dataset', (string) $suite['name']);
        $this->assertSame('2', (string) $suite['tests']);
        $this->assertSame('1', (string) $suite['failures']);
        $this->assertSame('Row 2', (string) $suite->testcase[1]['name']);
        $this->assertStringContainsString('first failure: Create account', (string) $suite->testcase[1]->failure['message']);
        $this->assertStringContainsString('Variables: email', (string) $suite->testcase[1]->failure);
    }

    public function test_an_empty_run_still_produces_a_valid_document(): void
    {
        $xml = $this->exporter->render('collection_run', $this->runResult([]));

        $suite = $this->parse($xml)->testsuite;
        $this->assertSame('0', (string) $suite['tests']);
        $this->assertCount(0, $suite->testcase);
    }
}
