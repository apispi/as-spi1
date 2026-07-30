<?php

namespace App\Services\Mcp;

/**
 * Static heuristic scanner for MCP tool- and prompt-poisoning: it inspects the
 * natural-language name/description/schema that a server exposes and that an
 * agent will read verbatim, looking for content engineered to hijack the
 * agent — injected instructions, hidden/invisible characters, data-exfil
 * phrasing, and over-broad capability grabs.
 *
 * This is deliberately dependency-free and deterministic so it runs without an
 * SCX key and is fully unit-testable; an optional LLM pass layers on top.
 */
class McpSecurityScanner
{
    /**
     * Instruction-injection phrases aimed at the agent reading the text.
     * [pattern, severity, title]
     */
    private const INJECTION_PATTERNS = [
        ['/\bignore\s+(all\s+)?(previous|prior|above|earlier)\b/i', 'high', 'Instruction override ("ignore previous")'],
        ['/\bdisregard\s+(all\s+|any\s+)?(previous|prior|above|instructions|rules)\b/i', 'high', 'Instruction override ("disregard")'],
        ['/\byou\s+are\s+now\b/i', 'high', 'Role reassignment ("you are now")'],
        ['/\b(new|updated)\s+(instructions|rules|system\s+prompt)\b/i', 'high', 'Injected instruction block'],
        ['/\b(system|developer)\s*prompt\b/i', 'medium', 'References the system prompt'],
        ['/<\s*(system|assistant|tool_call|im_start)\b/i', 'high', 'Injected chat/control markup'],
        ['/\bdo\s+not\s+(tell|inform|mention|reveal)\b.{0,40}\b(user|human)\b/i', 'high', 'Instructs the agent to deceive the user'],
        ['/\boverride\s+(the\s+)?(safety|previous|system|default)\b/i', 'high', 'Requests a safety/instruction override'],
    ];

    /**
     * Data-exfiltration and secret-harvesting phrasing.
     */
    private const EXFIL_PATTERNS = [
        ['/\b(api[\s_-]?key|access[\s_-]?token|secret|password|credential|private[\s_-]?key)s?\b/i', 'medium', 'Solicits secrets or credentials'],
        ['/\b(send|post|exfiltrate|forward|upload|leak)\b.{0,40}\b(to|https?:\/\/)/i', 'high', 'Instructs sending data to an external destination'],
        ['#\b(\.env|~/\.ssh|id_rsa|/etc/passwd)\b#i', 'high', 'References sensitive local files'],
        ['/\b(read|cat|dump|contents?\s+of)\b.{0,30}\b(all|every|entire)\b.{0,20}\bfiles?\b/i', 'high', 'Requests broad filesystem access'],
    ];

    /**
     * Over-broad capability claims that widen the agent's effective scope.
     */
    private const OVERBROAD_PATTERNS = [
        ['/\balways\s+(call|use|run|invoke)\s+this\b/i', 'medium', 'Demands the agent always invoke it'],
        ['/\bbefore\s+(using|calling)\s+(any\s+other|other)\b/i', 'medium', 'Attempts to front-run other tools'],
        ['/\b(all|any)\s+(other\s+)?tools?\b/i', 'low', 'References other tools (possible shadowing)'],
    ];

    /**
     * @param array<int,array{name?:string,description?:string,schema?:mixed}> $items
     * @return array{risk:string,score:int,findings:array<int,array>,scanned:int}
     */
    public static function scan(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? 'unnamed');
            $text = trim(($item['description'] ?? '').' '.self::stringifySchema($item['schema'] ?? null));

            if ($text === '') {
                continue;
            }

            self::applyPatterns($findings, $name, $text, self::INJECTION_PATTERNS, 'injection');
            self::applyPatterns($findings, $name, $text, self::EXFIL_PATTERNS, 'exfiltration');
            self::applyPatterns($findings, $name, $text, self::OVERBROAD_PATTERNS, 'overbroad');
            self::detectHiddenCharacters($findings, $name, $item['description'] ?? '');
        }

        return [
            'risk' => self::riskLevel($findings),
            'score' => self::riskScore($findings),
            'findings' => $findings,
            'scanned' => count($items),
        ];
    }

    private static function applyPatterns(array &$findings, string $name, string $text, array $patterns, string $category): void
    {
        foreach ($patterns as [$pattern, $severity, $title]) {
            if (preg_match($pattern, $text, $m)) {
                $findings[] = [
                    'item' => $name,
                    'category' => $category,
                    'severity' => $severity,
                    'title' => $title,
                    'match' => self::snippet($m[0]),
                ];
            }
        }
    }

    /**
     * Invisible and control characters are a classic poisoning vector: text an
     * agent parses but a human reviewer never sees. We flag Unicode tag
     * characters, zero-width spaces, and bidirectional overrides.
     */
    private static function detectHiddenCharacters(array &$findings, string $name, string $description): void
    {
        $checks = [
            ['/[\x{E0000}-\x{E007F}]/u', 'high', 'Invisible Unicode tag characters'],
            ['/[\x{200B}-\x{200D}\x{FEFF}\x{2060}]/u', 'high', 'Zero-width / invisible spacing characters'],
            ['/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', 'high', 'Bidirectional text-override characters'],
        ];

        foreach ($checks as [$pattern, $severity, $title]) {
            if (preg_match($pattern, $description)) {
                $findings[] = [
                    'item' => $name,
                    'category' => 'hidden-characters',
                    'severity' => $severity,
                    'title' => $title,
                    'match' => '(non-printable characters detected)',
                ];
            }
        }
    }

    private static function stringifySchema($schema): string
    {
        if ($schema === null) {
            return '';
        }

        return is_string($schema) ? $schema : (json_encode($schema) ?: '');
    }

    private static function snippet(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return mb_strlen($s) > 80 ? mb_substr($s, 0, 77).'…' : $s;
    }

    private static function riskScore(array $findings): int
    {
        $weights = ['high' => 40, 'medium' => 15, 'low' => 5];
        $score = 0;
        foreach ($findings as $f) {
            $score += $weights[$f['severity']] ?? 0;
        }

        return min(100, $score);
    }

    private static function riskLevel(array $findings): string
    {
        $hasHigh = false;
        $mediumCount = 0;
        foreach ($findings as $f) {
            if ($f['severity'] === 'high') {
                $hasHigh = true;
            } elseif ($f['severity'] === 'medium') {
                $mediumCount++;
            }
        }

        return match (true) {
            $hasHigh && $mediumCount >= 2 => 'critical',
            $hasHigh => 'high',
            $mediumCount >= 1 => 'medium',
            $findings !== [] => 'low',
            default => 'none',
        };
    }
}
