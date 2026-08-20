<?php

namespace App\Services\Export;

/**
 * Renders a saved request as a runnable snippet.
 *
 * {{variables}} are left in place: the snippet is for a human to paste, and
 * substituting a secret into copyable text is the opposite of what the secret
 * flag is for.
 */
class RequestExporter
{
    public const FORMATS = ['curl', 'fetch', 'python', 'http'];

    public function export(array $request, string $format): string
    {
        $method = strtoupper($request['method'] ?? 'GET');
        $url = (string) ($request['url'] ?? '');
        $headers = array_filter((array) ($request['headers'] ?? []), fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH);
        $body = $request['body'] ?? null;

        return match ($format) {
            'fetch' => $this->fetch($method, $url, $headers, $body),
            'python' => $this->python($method, $url, $headers, $body),
            'http' => $this->http($method, $url, $headers, $body),
            default => $this->curl($method, $url, $headers, $body),
        };
    }

    private function curl(string $method, string $url, array $headers, ?string $body): string
    {
        $lines = ["curl -X {$method} ".$this->shellQuote($url)];

        foreach ($headers as $name => $value) {
            $lines[] = '  -H '.$this->shellQuote($name.': '.$this->stringify($value));
        }

        if ($body !== null && $body !== '') {
            $lines[] = '  -d '.$this->shellQuote($body);
        }

        return implode(" \\\n", $lines);
    }

    private function fetch(string $method, string $url, array $headers, ?string $body): string
    {
        $options = ["  method: ".json_encode($method)];

        if ($headers !== []) {
            $encoded = json_encode(
                array_map(fn ($v) => $this->stringify($v), $headers),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            $options[] = '  headers: '.$this->indent($encoded, 2);
        }

        if ($body !== null && $body !== '') {
            $options[] = '  body: '.json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        return sprintf(
            "const response = await fetch(%s, {\n%s,\n});\n\nconsole.log(response.status, await response.text());",
            json_encode($url, JSON_UNESCAPED_SLASHES),
            implode(",\n", $options)
        );
    }

    private function python(string $method, string $url, array $headers, ?string $body): string
    {
        $lines = ['import requests', ''];

        if ($headers !== []) {
            $encoded = json_encode(
                array_map(fn ($v) => $this->stringify($v), $headers),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            $lines[] = 'headers = '.$this->indent($encoded, 0);
        }

        if ($body !== null && $body !== '') {
            $lines[] = 'body = '.json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $args = [json_encode($url, JSON_UNESCAPED_SLASHES)];
        if ($headers !== []) {
            $args[] = 'headers=headers';
        }
        if ($body !== null && $body !== '') {
            $args[] = 'data=body';
        }

        $lines[] = '';
        $lines[] = sprintf('response = requests.%s(%s)', strtolower($method), implode(', ', $args));
        $lines[] = 'print(response.status_code, response.text)';

        return implode("\n", $lines);
    }

    /**
     * Raw HTTP, the format most API docs show.
     */
    private function http(string $method, string $url, array $headers, ?string $body): string
    {
        $parts = parse_url($url);
        $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $lines = [sprintf('%s %s HTTP/1.1', $method, $target === '' ? '/' : $target)];

        if (isset($parts['host'])) {
            $lines[] = 'Host: '.$parts['host'];
        }

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$this->stringify($value);
        }

        if ($body !== null && $body !== '') {
            $lines[] = '';
            $lines[] = $body;
        }

        return implode("\n", $lines);
    }

    private function stringify(mixed $value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /**
     * Single-quote for a POSIX shell, escaping embedded single quotes the only
     * way a shell allows.
     */
    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function indent(string $block, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            fn ($line, $i) => $i === 0 ? $line : $pad.$line,
            explode("\n", $block),
            array_keys(explode("\n", $block))
        ));
    }
}
