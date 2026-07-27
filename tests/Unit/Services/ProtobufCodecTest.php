<?php

namespace Tests\Unit\Services;

use App\Services\Grpc\ProtobufCodec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProtobufCodecTest extends TestCase
{
    private ProtobufCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new ProtobufCodec;
    }

    public function test_encodes_a_string_field_to_canonical_wire_bytes(): void
    {
        // field 1, wire 2, len 2, "hi"
        $bytes = $this->codec->encode([['field' => 1, 'type' => 'string', 'value' => 'hi']]);

        $this->assertSame('0a026869', bin2hex($bytes));
    }

    public function test_encodes_a_varint_field(): void
    {
        $bytes = $this->codec->encode([['field' => 1, 'type' => 'int32', 'value' => 150]]);

        $this->assertSame('089601', bin2hex($bytes));
    }

    public function test_encodes_sint_with_zigzag(): void
    {
        $bytes = $this->codec->encode([['field' => 1, 'type' => 'sint32', 'value' => -1]]);

        $this->assertSame('0801', bin2hex($bytes));
    }

    public function test_encodes_a_nested_message(): void
    {
        $bytes = $this->codec->encode([[
            'field' => 2,
            'type' => 'message',
            'value' => [['field' => 1, 'type' => 'string', 'value' => 'hi']],
        ]]);

        $this->assertSame('12040a026869', bin2hex($bytes));
    }

    public function test_encodes_repeated_fields(): void
    {
        $bytes = $this->codec->encode([[
            'field' => 3, 'type' => 'int32', 'repeated' => true, 'value' => [1, 2],
        ]]);

        $this->assertSame('18011802', bin2hex($bytes));
    }

    public function test_encodes_fixed_and_float_types_little_endian(): void
    {
        $this->assertSame('0d0000803f', bin2hex(
            $this->codec->encode([['field' => 1, 'type' => 'float', 'value' => 1.0]])
        ));
        $this->assertSame('09000000000000f03f', bin2hex(
            $this->codec->encode([['field' => 1, 'type' => 'double', 'value' => 1.0]])
        ));
    }

    public function test_rejects_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->encode([['field' => 1, 'type' => 'widget', 'value' => 'x']]);
    }

    public function test_rejects_missing_field_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->encode([['type' => 'string', 'value' => 'x']]);
    }

    public function test_decodes_a_string_field_without_treating_it_as_a_message(): void
    {
        $decoded = $this->codec->decode(hex2bin('0a026869'));

        $this->assertCount(1, $decoded);
        $this->assertSame(1, $decoded[0]['field']);
        $this->assertSame(ProtobufCodec::WIRE_LEN, $decoded[0]['wire_type']);
        $this->assertSame('hi', $decoded[0]['string']);
        $this->assertArrayNotHasKey('message', $decoded[0]);
    }

    public function test_decodes_a_varint_field(): void
    {
        $decoded = $this->codec->decode(hex2bin('089601'));

        $this->assertSame(150, $decoded[0]['varint']);
    }

    public function test_decodes_a_nested_message(): void
    {
        // field 2 { field 1 int32 = 7 }
        $decoded = $this->codec->decode(hex2bin('1202' . '0807'));

        $this->assertArrayHasKey('message', $decoded[0]);
        $this->assertSame(7, $decoded[0]['message'][0]['varint']);
    }

    public function test_encode_then_decode_round_trips(): void
    {
        $bytes = $this->codec->encode([
            ['field' => 1, 'type' => 'string', 'value' => 'name'],
            ['field' => 2, 'type' => 'int32', 'value' => 42],
        ]);

        $decoded = $this->codec->decode($bytes);

        $this->assertSame('name', $decoded[0]['string']);
        $this->assertSame(42, $decoded[1]['varint']);
    }
}
