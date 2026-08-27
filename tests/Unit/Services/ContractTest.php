<?php

namespace Tests\Unit\Services;

use App\Services\Contracts\ContractChecker;
use App\Services\Contracts\SchemaInferrer;
use PHPUnit\Framework\TestCase;

class ContractTest extends TestCase
{
    private SchemaInferrer $inferrer;
    private ContractChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inferrer = new SchemaInferrer;
        $this->checker = new ContractChecker;
    }

    public function test_it_infers_object_shape_types_and_required(): void
    {
        $schema = $this->inferrer->infer([
            'id' => 7, 'name' => 'Ada', 'active' => true, 'score' => 4.5, 'tags' => ['x'],
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertSame('number', $schema['properties']['score']['type']);
        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'active', 'score', 'tags'], $schema['required']);
    }

    public function test_it_detects_string_formats(): void
    {
        $schema = $this->inferrer->infer([
            'created' => '2026-08-28T10:00:00Z', 'email' => 'a@b.com', 'site' => 'https://x.io',
        ]);

        $this->assertSame('date-time', $schema['properties']['created']['format']);
        $this->assertSame('email', $schema['properties']['email']['format']);
        $this->assertSame('uri', $schema['properties']['site']['format']);
    }

    public function test_array_required_is_the_intersection_across_elements(): void
    {
        // "email" only appears on the first row, so it is not required.
        $schema = $this->inferrer->infer([
            ['id' => 1, 'email' => 'a@b.com'],
            ['id' => 2],
        ]);

        $item = $schema['items'];
        $this->assertSame(['id'], $item['required']);
        // But it is still a known property.
        $this->assertArrayHasKey('email', $item['properties']);
    }

    public function test_a_conforming_response_reports_no_drift(): void
    {
        $contract = $this->inferrer->infer(['id' => 1, 'name' => 'Ada']);

        $result = $this->checker->check($contract, ['id' => 2, 'name' => 'Bob']);

        $this->assertTrue($result['conforms']);
        $this->assertFalse($result['breaking']);
    }

    public function test_a_removed_required_field_is_breaking(): void
    {
        $contract = $this->inferrer->infer(['id' => 1, 'email' => 'a@b.com']);

        $result = $this->checker->check($contract, ['id' => 2]);

        $this->assertTrue($result['breaking']);
        $this->assertSame('$.email', $result['removed'][0]['path']);
    }

    public function test_a_type_change_is_breaking_and_located(): void
    {
        // price was a number, now a string — the classic silent break.
        $contract = $this->inferrer->infer(['price' => 9.99]);

        $result = $this->checker->check($contract, ['price' => '9.99']);

        $this->assertTrue($result['breaking']);
        $this->assertSame('$.price', $result['type_changed'][0]['path']);
        $this->assertSame('number', $result['type_changed'][0]['expected']);
        $this->assertSame('string', $result['type_changed'][0]['actual']);
    }

    public function test_a_new_field_is_additive_not_breaking(): void
    {
        $contract = $this->inferrer->infer(['id' => 1]);

        $result = $this->checker->check($contract, ['id' => 1, 'nickname' => 'ada']);

        $this->assertFalse($result['breaking']);
        $this->assertFalse($result['conforms']); // noteworthy, just not breaking
        $this->assertSame('$.nickname', $result['added'][0]['path']);
    }

    public function test_integer_still_satisfies_a_number_contract(): void
    {
        $contract = $this->inferrer->infer(['n' => 1.5]);

        $this->assertTrue($this->checker->check($contract, ['n' => 3])['conforms']);
    }

    public function test_it_locates_a_break_inside_an_array_element(): void
    {
        $contract = $this->inferrer->infer(['items' => [['id' => 1]]]);

        $result = $this->checker->check($contract, ['items' => [['id' => 1], ['id' => 'two']]]);

        $this->assertTrue($result['breaking']);
        $this->assertSame('$.items[1].id', $result['type_changed'][0]['path']);
    }

    public function test_a_non_json_body_infers_no_contract(): void
    {
        $this->assertNull($this->inferrer->fromBody('plain text'));
        $this->assertNull($this->inferrer->fromBody(''));
        $this->assertNotNull($this->inferrer->fromBody('{"a":1}'));
    }
}
