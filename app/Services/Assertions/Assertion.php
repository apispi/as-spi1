<?php

namespace App\Services\Assertions;

/**
 * The vocabulary an assertion may use.
 *
 * This list is deliberately closed. The AI assertion generator is constrained
 * to it (see AiAssistController::assert), because an open vocabulary produces
 * "contains", "includes", and "has" all meaning the same thing, and nothing
 * can evaluate the result.
 */
class Assertion
{
    /**
     * operator => whether it needs an `expected` value.
     */
    public const OPERATORS = [
        'equals' => true,
        'not_equals' => true,
        'contains' => true,
        'not_contains' => true,
        'matches' => true,          // regex, delimiters optional
        'exists' => false,
        'not_exists' => false,
        'greater_than' => true,
        'greater_or_equal' => true,
        'less_than' => true,
        'less_or_equal' => true,
        'is_type' => true,          // string|number|boolean|array|object|null
        'has_length' => true,       // string length or array/object count
    ];

    public const TYPES = ['string', 'number', 'boolean', 'array', 'object', 'null'];

    /**
     * Paths outside the response body. Anything else is a path into the
     * decoded JSON body, dot-notated, with an optional leading "$.".
     */
    public const STATUS_PATH = 'status';

    public const TIME_PATH = 'time_ms';

    public const HEADER_PREFIX = 'header.';

    public static function operators(): array
    {
        return array_keys(self::OPERATORS);
    }

    public static function needsExpected(string $operator): bool
    {
        return self::OPERATORS[$operator] ?? false;
    }
}
