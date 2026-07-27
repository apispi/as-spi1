<?php

namespace App\Services\Grpc;

use InvalidArgumentException;

/**
 * A minimal protobuf wire-format codec for the gRPC tester.
 *
 * Rather than compile .proto files, the tester describes a request message as
 * an explicit list of fields — each with a field number, a scalar/message
 * type, and a value — which this encoder turns into wire bytes. Responses are
 * decoded generically: the wire format carries field numbers and wire types
 * (but not names or exact scalar types), so decode() returns each field with
 * every plausible interpretation of its bytes for the caller to read.
 *
 * Wire types (https://protobuf.dev/programming-guides/encoding/):
 *   0 VARINT, 1 I64, 2 LEN, 5 I32.
 */
class ProtobufCodec
{
    public const WIRE_VARINT = 0;
    public const WIRE_I64 = 1;
    public const WIRE_LEN = 2;
    public const WIRE_I32 = 5;

    /** type => wire type */
    protected const TYPE_WIRE = [
        'int32' => self::WIRE_VARINT, 'int64' => self::WIRE_VARINT,
        'uint32' => self::WIRE_VARINT, 'uint64' => self::WIRE_VARINT,
        'sint32' => self::WIRE_VARINT, 'sint64' => self::WIRE_VARINT,
        'bool' => self::WIRE_VARINT, 'enum' => self::WIRE_VARINT,
        'fixed64' => self::WIRE_I64, 'sfixed64' => self::WIRE_I64, 'double' => self::WIRE_I64,
        'string' => self::WIRE_LEN, 'bytes' => self::WIRE_LEN, 'message' => self::WIRE_LEN,
        'fixed32' => self::WIRE_I32, 'sfixed32' => self::WIRE_I32, 'float' => self::WIRE_I32,
    ];

    /**
     * Encode a list of field descriptors into a protobuf message.
     *
     * @param  array<int, array{field:int, type:string, value:mixed, repeated?:bool}>  $fields
     */
    public function encode(array $fields): string
    {
        $out = '';

        foreach ($fields as $i => $field) {
            $number = $field['field'] ?? null;
            $type = $field['type'] ?? null;

            if (! is_int($number) && ! (is_string($number) && ctype_digit($number))) {
                throw new InvalidArgumentException("Field #{$i} is missing a numeric \"field\".");
            }
            $number = (int) $number;

            if (! isset(self::TYPE_WIRE[$type])) {
                throw new InvalidArgumentException("Field {$number} has an unknown type \"".(is_string($type) ? $type : gettype($type)).'".');
            }

            $values = ! empty($field['repeated']) && is_array($field['value'])
                ? $field['value']
                : [$field['value'] ?? null];

            foreach ($values as $value) {
                $out .= $this->encodeField($number, $type, $value);
            }
        }

        return $out;
    }

    protected function encodeField(int $number, string $type, mixed $value): string
    {
        $wire = self::TYPE_WIRE[$type];
        $tag = $this->encodeVarint(($number << 3) | $wire);

        return match ($wire) {
            self::WIRE_VARINT => $tag.$this->encodeVarint($this->varintValue($type, $value)),
            self::WIRE_I64 => $tag.$this->encodeI64($type, $value),
            self::WIRE_I32 => $tag.$this->encodeI32($type, $value),
            self::WIRE_LEN => $tag.$this->encodeLen($type, $value),
        };
    }

    protected function varintValue(string $type, mixed $value): int
    {
        if ($type === 'bool') {
            return $value ? 1 : 0;
        }
        $int = (int) $value;
        if ($type === 'sint32' || $type === 'sint64') {
            return $this->zigzagEncode($int);
        }

        return $int;
    }

    protected function encodeI64(string $type, mixed $value): string
    {
        return match ($type) {
            'double' => pack('e', (float) $value),
            'sfixed64' => pack('P', (int) $value),
            default => pack('P', (int) $value), // fixed64
        };
    }

    protected function encodeI32(string $type, mixed $value): string
    {
        return match ($type) {
            'float' => pack('g', (float) $value),
            default => pack('V', ((int) $value) & 0xFFFFFFFF), // fixed32 / sfixed32
        };
    }

    protected function encodeLen(string $type, mixed $value): string
    {
        if ($type === 'message') {
            $inner = is_array($value) ? $this->encode($value) : '';

            return $this->encodeVarint(strlen($inner)).$inner;
        }

        $str = (string) $value;

        return $this->encodeVarint(strlen($str)).$str;
    }

    /**
     * Generically decode a protobuf message into a list of fields, each
     * annotated with every plausible interpretation of its bytes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function decode(string $data, int $depth = 0): array
    {
        $fields = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            $tag = $this->readVarint($data, $offset);
            if ($tag === null) {
                break;
            }
            $number = $tag >> 3;
            $wire = $tag & 0x07;

            $entry = ['field' => $number, 'wire_type' => $wire];

            if ($wire === self::WIRE_VARINT) {
                $v = $this->readVarint($data, $offset);
                if ($v === null) {
                    break;
                }
                $entry['varint'] = $v;
                $entry['signed'] = $this->zigzagDecode($v);
                $entry['bool'] = (bool) $v;
            } elseif ($wire === self::WIRE_I64) {
                if ($offset + 8 > $len) {
                    break;
                }
                $chunk = substr($data, $offset, 8);
                $offset += 8;
                $entry['uint64'] = unpack('P', $chunk)[1];
                $entry['double'] = unpack('e', $chunk)[1];
            } elseif ($wire === self::WIRE_I32) {
                if ($offset + 4 > $len) {
                    break;
                }
                $chunk = substr($data, $offset, 4);
                $offset += 4;
                $entry['uint32'] = unpack('V', $chunk)[1];
                $entry['float'] = round(unpack('g', $chunk)[1], 6);
            } elseif ($wire === self::WIRE_LEN) {
                $l = $this->readVarint($data, $offset);
                if ($l === null || $offset + $l > $len) {
                    break;
                }
                $bytes = substr($data, $offset, $l);
                $offset += $l;
                $entry['length'] = $l;
                $isText = $l > 0 && $this->looksLikeText($bytes);
                if ($isText) {
                    $entry['string'] = $bytes;
                }
                $entry['base64'] = base64_encode($bytes);
                // Length-delimited bytes are ambiguous between a string and a
                // nested message. Only try a nested decode when the bytes are
                // not clean printable text, to avoid decoding "hi" as a message.
                if ($l > 0 && ! $isText && $depth < 6) {
                    $nested = $this->tryDecodeNested($bytes, $depth + 1);
                    if ($nested !== null) {
                        $entry['message'] = $nested;
                    }
                }
            } else {
                // Unknown/unsupported wire type (groups): stop, we cannot frame it.
                break;
            }

            $fields[] = $entry;
        }

        return $fields;
    }

    /**
     * Attempt to decode bytes as a nested message; returns null if the bytes
     * do not cleanly consume as protobuf (so the caller keeps the string form).
     */
    protected function tryDecodeNested(string $bytes, int $depth): ?array
    {
        $offset = 0;
        $len = strlen($bytes);
        while ($offset < $len) {
            $tag = $this->readVarint($bytes, $offset);
            if ($tag === null || ($tag >> 3) === 0) {
                return null;
            }
            $wire = $tag & 0x07;
            if ($wire === self::WIRE_VARINT) {
                if ($this->readVarint($bytes, $offset) === null) {
                    return null;
                }
            } elseif ($wire === self::WIRE_I64) {
                $offset += 8;
            } elseif ($wire === self::WIRE_I32) {
                $offset += 4;
            } elseif ($wire === self::WIRE_LEN) {
                $l = $this->readVarint($bytes, $offset);
                if ($l === null) {
                    return null;
                }
                $offset += $l;
            } else {
                return null;
            }
            if ($offset > $len) {
                return null;
            }
        }

        return $this->decode($bytes, $depth);
    }

    /**
     * True when bytes are valid UTF-8 with no control characters other than
     * common whitespace — i.e. they read as a human string rather than a
     * packed/nested message.
     */
    protected function looksLikeText(string $bytes): bool
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return false;
        }

        return preg_match('/[\x00-\x08\x0E-\x1F]/', $bytes) === 0;
    }

    public function encodeVarint(int $value): string
    {
        // Negative numbers are encoded as fixed 10-byte two's-complement varints.
        if ($value < 0) {
            $out = '';
            for ($i = 0; $i < 10; $i++) {
                $byte = ($value >> ($i * 7)) & 0x7F;
                $out .= chr($i === 9 ? $byte : ($byte | 0x80));
            }

            return $out;
        }

        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value !== 0 ? ($byte | 0x80) : $byte);
        } while ($value !== 0);

        return $out;
    }

    /**
     * Read a base-128 varint at $offset, advancing it. Returns null if the
     * buffer ends mid-varint.
     */
    public function readVarint(string $data, int &$offset): ?int
    {
        $result = 0;
        $shift = 0;
        $len = strlen($data);

        while ($offset < $len && $shift < 64) {
            $byte = ord($data[$offset]);
            $offset++;
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }

        return null;
    }

    protected function zigzagEncode(int $n): int
    {
        return ($n << 1) ^ ($n >> 63);
    }

    protected function zigzagDecode(int $n): int
    {
        return ($n >> 1) ^ -($n & 1);
    }
}
