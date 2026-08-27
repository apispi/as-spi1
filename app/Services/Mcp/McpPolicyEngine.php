<?php

namespace App\Services\Mcp;

/**
 * The MCP firewall: evaluates an ordered policy against traffic passing through
 * the flight-recorder relay and decides what to do inline.
 *
 * A rule is {action, direction, tool?, pattern?, on_injection?}:
 *  - action    'block' | 'redact'
 *  - direction 'request' | 'response'
 *  - tool      regex on the tool name (request side; null = any tool)
 *  - pattern   regex; for redact = key/value to mask; for a response block =
 *              trigger if it matches the response text
 *  - on_injection  response side: trigger when the injection scanner flagged it
 *
 * Rules are applied in order. The first block wins and stops evaluation;
 * redactions accumulate. Everything is pure so it is exhaustively testable
 * without a network.
 */
class McpPolicyEngine
{
    public const MASK = '••••••';

    /**
     * @return array{action:'allow'|'block', arguments:mixed, redactions:int, note:?string, rule:?int}
     */
    public function evaluateRequest(array $policy, ?array $requestJson): array
    {
        $tool = $requestJson['params']['name'] ?? null;
        $arguments = $requestJson['params']['arguments'] ?? null;

        // Only tool calls carry a tool name / arguments to act on.
        if (($requestJson['method'] ?? null) !== 'tools/call' || ! is_string($tool)) {
            return ['action' => 'allow', 'arguments' => $arguments, 'redactions' => 0, 'note' => null, 'rule' => null];
        }

        $redactions = 0;

        foreach ($policy as $i => $rule) {
            if (($rule['direction'] ?? 'request') !== 'request') {
                continue;
            }
            if (! $this->toolMatches($rule['tool'] ?? null, $tool)) {
                continue;
            }

            if (($rule['action'] ?? null) === 'block') {
                return [
                    'action' => 'block', 'arguments' => $arguments, 'redactions' => $redactions,
                    'note' => "Tool \"{$tool}\" blocked by policy.", 'rule' => $i,
                ];
            }

            if (($rule['action'] ?? null) === 'redact' && ! empty($rule['pattern']) && is_array($arguments)) {
                [$arguments, $n] = $this->maskMatching($arguments, $rule['pattern']);
                $redactions += $n;
            }
        }

        return [
            'action' => 'allow', 'arguments' => $arguments, 'redactions' => $redactions,
            'note' => $redactions ? "Redacted {$redactions} argument value(s)." : null, 'rule' => null,
        ];
    }

    /**
     * @return array{action:'allow'|'block'|'redact', result:mixed, redactions:int, note:?string, rule:?int}
     */
    public function evaluateResponse(array $policy, ?array $responseJson, bool $flagged): array
    {
        $result = $responseJson;
        $redactions = 0;

        foreach ($policy as $i => $rule) {
            if (($rule['direction'] ?? null) !== 'response') {
                continue;
            }

            $onInjection = ! empty($rule['on_injection']) && $flagged;
            $matches = ! empty($rule['pattern']) && is_array($responseJson)
                && $this->textMatches($rule['pattern'], json_encode($responseJson, JSON_UNESCAPED_SLASHES));

            if (! $onInjection && ! $matches) {
                continue;
            }

            if (($rule['action'] ?? null) === 'block') {
                return [
                    'action' => 'block', 'result' => null, 'redactions' => $redactions,
                    'note' => $onInjection ? 'Response withheld: injection detected.' : 'Response withheld by policy.',
                    'rule' => $i,
                ];
            }

            if (($rule['action'] ?? null) === 'redact' && ! empty($rule['pattern']) && is_array($result)) {
                [$result, $n] = $this->maskMatching($result, $rule['pattern']);
                $redactions += $n;
            }
        }

        return [
            'action' => $redactions ? 'redact' : 'allow', 'result' => $result, 'redactions' => $redactions,
            'note' => $redactions ? "Redacted {$redactions} response value(s)." : null, 'rule' => null,
        ];
    }

    /**
     * Mask string leaves whose KEY or VALUE matches the pattern, recursing
     * through the structure. Returns [masked, count].
     */
    private function maskMatching(array $data, string $pattern, ?string $parentKey = null): array
    {
        $count = 0;
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                [$sub, $n] = $this->maskMatching($value, $pattern, (string) $key);
                $out[$key] = $sub;
                $count += $n;

                continue;
            }

            if (is_string($value) && ($this->textMatches($pattern, (string) $key) || $this->textMatches($pattern, $value))) {
                $out[$key] = self::MASK;
                $count++;

                continue;
            }

            $out[$key] = $value;
        }

        return [$out, $count];
    }

    private function toolMatches(?string $rule, string $tool): bool
    {
        if ($rule === null || $rule === '') {
            return true;
        }

        return $this->textMatches($rule, $tool);
    }

    /**
     * Test a user-supplied regex safely. A bare pattern is wrapped as
     * case-insensitive; an invalid regex never matches (and is rejected at
     * save time by the controller).
     */
    private function textMatches(string $pattern, string $subject): bool
    {
        return self::safeMatch(self::normalise($pattern), $subject) === 1;
    }

    /**
     * Validate a policy array (used by the controller). Returns the first
     * error message, or null when valid.
     */
    public static function validate(array $policy): ?string
    {
        foreach ($policy as $i => $rule) {
            if (! in_array($rule['action'] ?? null, ['block', 'redact'], true)) {
                return "Rule ".($i + 1).": action must be block or redact.";
            }
            if (! in_array($rule['direction'] ?? null, ['request', 'response'], true)) {
                return "Rule ".($i + 1).": direction must be request or response.";
            }
            if (($rule['action'] ?? null) === 'redact' && empty($rule['pattern'])) {
                return "Rule ".($i + 1).": a redact rule needs a pattern.";
            }
            foreach (['tool', 'pattern'] as $field) {
                $p = $rule[$field] ?? null;
                if (is_string($p) && $p !== '' && self::safeMatch(self::normalise($p), '') === false) {
                    return "Rule ".($i + 1).": invalid regular expression in {$field}.";
                }
            }
        }

        return null;
    }

    /**
     * Run preg_match with warnings fully suppressed (PHPUnit's error handler
     * ignores the @ operator), returning false on an invalid pattern.
     */
    private static function safeMatch(string $regex, string $subject): int|false
    {
        set_error_handler(fn () => true);
        try {
            return preg_match($regex, $subject);
        } finally {
            restore_error_handler();
        }
    }

    private static function normalise(string $pattern): string
    {
        return preg_match('/^([\/#~%]).*\1[a-zA-Z]*$/', $pattern)
            ? $pattern
            : '/'.str_replace('/', '\/', $pattern).'/i';
    }
}
