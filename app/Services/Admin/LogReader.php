<?php

namespace App\Services\Admin;

/**
 * Reads and parses Laravel log files for the admin log viewer.
 *
 * Only the tail of a file is read, so a large log never loads whole into
 * memory. Standard Monolog lines are parsed into structured entries; the
 * continuation lines of a multi-line entry (stack traces, context) are folded
 * back into the entry they belong to.
 */
class LogReader
{
    /** Default bytes read from the end of the file. */
    public const TAIL_BYTES = 256 * 1024;

    /** A new entry begins with "[YYYY-MM-DD HH:MM:SS...]". */
    private const ENTRY = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:[+-]\d{2}:?\d{2})?\]\s+([a-z0-9_-]+)\.([A-Z]+):\s?(.*)$/s';

    /** Log levels, most severe first — the order the viewer filters by. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * Parse the tail of $path into entries, newest first.
     *
     * @return array{entries: array<int,array<string,mixed>>, counts: array<string,int>, bytes: int}
     */
    public function read(string $path, int $tailBytes = self::TAIL_BYTES): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['entries' => [], 'counts' => [], 'bytes' => 0];
        }

        $text = $this->tail($path, $tailBytes);
        $entries = $this->parse($text);

        // Newest first.
        $entries = array_reverse($entries);

        $counts = [];
        foreach ($entries as $e) {
            $counts[$e['level']] = ($counts[$e['level']] ?? 0) + 1;
        }

        return ['entries' => $entries, 'counts' => $counts, 'bytes' => strlen($text)];
    }

    /**
     * Filter parsed entries by level (this level or more severe) and a text
     * query, then cap the count.
     *
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<int,array<string,mixed>>
     */
    public function filter(array $entries, ?string $level, ?string $query, int $limit): array
    {
        $minRank = $level ? array_search(strtolower($level), self::LEVELS, true) : false;
        $q = $query !== null && $query !== '' ? mb_strtolower($query) : null;

        $out = [];
        foreach ($entries as $e) {
            if ($minRank !== false) {
                $rank = array_search($e['level'], self::LEVELS, true);
                // A lower index means more severe; keep entries at least as severe.
                if ($rank === false || $rank > $minRank) {
                    continue;
                }
            }
            if ($q !== null && ! str_contains(mb_strtolower($e['message'].' '.$e['channel'].' '.$e['detail']), $q)) {
                continue;
            }
            $out[] = $e;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parse(string $text): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY, $line, $m)) {
                if ($current !== null) {
                    $entries[] = $this->finalize($current);
                }
                $current = [
                    'time' => $m[1],
                    'channel' => $m[2],
                    'level' => strtolower($m[3]),
                    'message' => rtrim($m[4]),
                    'detail_lines' => [],
                ];
            } elseif ($current !== null) {
                // Continuation of a multi-line entry (stack trace / context).
                $current['detail_lines'][] = $line;
            }
            // A leading fragment before the first entry header is dropped: it is
            // the truncated head of an entry the tail cut through.
        }

        if ($current !== null) {
            $entries[] = $this->finalize($current);
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function finalize(array $entry): array
    {
        $detail = rtrim(implode("\n", $entry['detail_lines']));
        // Keep the panel light: cap a giant stack trace.
        if (mb_strlen($detail) > 8000) {
            $detail = mb_substr($detail, 0, 8000)."\n… (truncated)";
        }
        unset($entry['detail_lines']);
        $entry['detail'] = $detail;

        return $entry;
    }

    /**
     * Read the last $bytes of a file, starting from a line boundary so the
     * first partial entry is clean.
     */
    private function tail(string $path, int $bytes): string
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        if ($size > $bytes) {
            fseek($handle, -$bytes, SEEK_END);
            fgets($handle); // discard the partial first line
        }

        $text = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $text;
    }
}
