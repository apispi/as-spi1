<?php

namespace App\Services\Fuzz;

/**
 * Generates adversarial variants of a JSON request body, targeted at its real
 * fields.
 *
 * Because we know each field's type (from the body itself), the mutations are
 * pointed rather than random: a string field gets emptied, oversized, and
 * hit with injection payloads and wrong types; a number field gets boundary
 * and type violations; required fields get dropped; and the whole body gets
 * structural abuse. Sending these and watching the responses answers "does the
 * endpoint handle bad input gracefully, or does it crash / accept garbage?"
 */
class FuzzGenerator
{
    /** Keep a run cheap and bounded. */
    public const MAX_VARIANTS = 60;

    private const INJECTION = [
        "' OR '1'='1",
        '<script>alert(1)</script>',
        '../../../../etc/passwd',
        "\0nullbyte",
        '${jndi:ldap://x}',
    ];

    /**
     * @return array<int,array{label:string,expects_reject:bool,body:mixed}>
     *   `expects_reject` = a well-behaved API should reject this with a 4xx.
     */
    public function generate(mixed $decoded): array
    {
        $variants = [
            // Control: the original body should be accepted.
            ['label' => 'baseline (unchanged)', 'expects_reject' => false, 'body' => $decoded],
        ];

        if (is_array($decoded) && ! array_is_list($decoded)) {
            $this->mutateObject($variants, $decoded);
        }

        // Structural abuse regardless of the original shape.
        $variants[] = ['label' => 'body: null', 'expects_reject' => true, 'body' => null];
        $variants[] = ['label' => 'body: empty object', 'expects_reject' => true, 'body' => (object) []];
        $variants[] = ['label' => 'body: array instead of object', 'expects_reject' => true, 'body' => [1, 2, 3]];
        $variants[] = ['label' => 'body: deeply nested', 'expects_reject' => true, 'body' => $this->deepNest(40)];

        return array_slice($variants, 0, self::MAX_VARIANTS);
    }

    private function mutateObject(array &$variants, array $object): void
    {
        foreach ($object as $key => $value) {
            // Every field: drop it (tests required-field handling).
            $variants[] = [
                'label' => "omit \"{$key}\"",
                'expects_reject' => true,
                'body' => $this->without($object, $key),
            ];

            $type = $this->typeOf($value);

            if ($type === 'string') {
                $variants[] = ['label' => "\"{$key}\" = \"\" (empty)", 'expects_reject' => false, 'body' => $this->with($object, $key, '')];
                $variants[] = ['label' => "\"{$key}\" = 10k chars (oversized)", 'expects_reject' => false, 'body' => $this->with($object, $key, str_repeat('A', 10000))];
                foreach (self::INJECTION as $i => $payload) {
                    $variants[] = [
                        'label' => "\"{$key}\" = injection #".($i + 1),
                        'expects_reject' => false,
                        'body' => $this->with($object, $key, $payload),
                    ];
                }
                $variants[] = ['label' => "\"{$key}\" = 12345 (wrong type)", 'expects_reject' => true, 'body' => $this->with($object, $key, 12345)];
                $variants[] = ['label' => "\"{$key}\" = null", 'expects_reject' => true, 'body' => $this->with($object, $key, null)];
            }

            if (in_array($type, ['integer', 'number'], true)) {
                foreach ([0, -1, 2147483648, 1.0e308, 'not-a-number', null] as $bad) {
                    $isTypeViolation = ! is_int($bad) && ! is_float($bad); // string / null
                    $variants[] = [
                        'label' => "\"{$key}\" = ".$this->render($bad).($isTypeViolation ? ' (wrong type)' : ' (boundary)'),
                        'expects_reject' => $isTypeViolation,
                        'body' => $this->with($object, $key, $bad),
                    ];
                }
            }

            if ($type === 'boolean') {
                $variants[] = ['label' => "\"{$key}\" = \"true\" (string)", 'expects_reject' => true, 'body' => $this->with($object, $key, 'true')];
                $variants[] = ['label' => "\"{$key}\" = null", 'expects_reject' => true, 'body' => $this->with($object, $key, null)];
            }
        }
    }

    private function with(array $object, int|string $key, mixed $value): array
    {
        $object[$key] = $value;

        return $object;
    }

    private function without(array $object, int|string $key): array
    {
        unset($object[$key]);

        return $object;
    }

    private function deepNest(int $depth): array
    {
        $node = ['x' => 1];
        for ($i = 0; $i < $depth; $i++) {
            $node = ['nested' => $node];
        }

        return $node;
    }

    private function typeOf(mixed $v): string
    {
        return match (true) {
            is_bool($v) => 'boolean',
            is_int($v) => 'integer',
            is_float($v) => 'number',
            is_string($v) => 'string',
            is_array($v) && array_is_list($v) => 'array',
            is_array($v) => 'object',
            default => 'null',
        };
    }

    private function render(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_float($v)) {
            return '1e308';
        }

        return (string) $v;
    }
}
