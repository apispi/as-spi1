<?php

namespace App\Services\Import;

/**
 * Turns a pasted `curl` command into a request Spi can send.
 *
 * Aimed at what people actually paste — the "Copy as cURL" output from browser
 * devtools, and snippets from API docs. Tokenising is done by hand rather than
 * with a regex per flag, because quoting is the whole problem: a JSON body
 * arrives wrapped in single quotes and full of double quotes, and line
 * continuations are everywhere.
 */
class CurlImporter
{
    /**
     * Flags that take a value we care about.
     */
    private const VALUE_FLAGS = [
        '-X' => 'method', '--request' => 'method',
        '-H' => 'header', '--header' => 'header',
        '-d' => 'body', '--data' => 'body', '--data-raw' => 'body',
        '--data-binary' => 'body', '--data-ascii' => 'body',
        '-u' => 'userpass', '--user' => 'userpass',
        '--url' => 'url',
    ];

    /**
     * Value flags we accept but deliberately drop (connection tuning, output
     * control) — they have no meaning for a stored request.
     */
    private const IGNORED_VALUE_FLAGS = [
        '-o', '--output', '-A', '--user-agent', '-e', '--referer',
        '--connect-timeout', '--max-time', '-m', '--retry', '-w', '--write-out',
        '--cookie-jar', '-c', '--cert', '--key', '--proxy', '-x',
    ];

    /**
     * @return array{method: string, url: string, headers: array, body: string|null, warnings: array}
     */
    public function parse(string $command): array
    {
        $tokens = $this->tokenise($command);

        if ($tokens === [] || ! str_contains(strtolower($tokens[0]), 'curl')) {
            throw new ImportException('That does not look like a curl command.');
        }

        array_shift($tokens);

        $method = null;
        $url = null;
        $headers = [];
        $bodyParts = [];
        $warnings = [];
        $isForm = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (isset(self::VALUE_FLAGS[$token])) {
                $value = $tokens[++$i] ?? '';

                switch (self::VALUE_FLAGS[$token]) {
                    case 'method':
                        $method = strtoupper($value);
                        break;
                    case 'url':
                        $url = $value;
                        break;
                    case 'header':
                        [$name, $headerValue] = $this->splitHeader($value);
                        if ($name !== null) {
                            $headers[$name] = $headerValue;
                        }
                        break;
                    case 'body':
                        $bodyParts[] = $value;
                        break;
                    case 'userpass':
                        // Translate basic auth into the header it becomes on
                        // the wire, so the request is self-contained.
                        $headers['Authorization'] = 'Basic '.base64_encode($value);
                        break;
                }

                continue;
            }

            if (in_array($token, self::IGNORED_VALUE_FLAGS, true)) {
                $i++;   // skip its value too

                continue;
            }

            // --form/-F: multipart. Captured as a warning rather than silently
            // producing a body that would not reproduce the request.
            if (in_array($token, ['-F', '--form'], true)) {
                $i++;
                $isForm = true;

                continue;
            }

            if ($token === '--compressed' || str_starts_with($token, '-')) {
                // Remaining boolean flags (-k, -L, -s, -v...) do not change
                // what is sent, so they are dropped without comment.
                continue;
            }

            if ($url === null) {
                $url = $token;
            }
        }

        if ($isForm) {
            $warnings[] = 'Multipart form fields (-F) were not imported; add the body by hand.';
        }

        if ($url === null || $url === '') {
            throw new ImportException('No URL found in that curl command.');
        }

        $url = $this->cleanUrl($url);
        $body = $bodyParts === [] ? null : implode('&', $bodyParts);

        // curl implies POST when there is a body and no explicit method.
        if ($method === null) {
            $method = $body !== null ? 'POST' : 'GET';
        }

        if ($body !== null && ! $this->hasHeader($headers, 'content-type')) {
            // Match curl's own default so the request behaves the same.
            $headers['Content-Type'] = $this->looksLikeJson($body)
                ? 'application/json'
                : 'application/x-www-form-urlencoded';
        }

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'warnings' => $warnings,
        ];
    }

    /**
     * Split a command into tokens, honouring single quotes, double quotes,
     * backslash escapes, and line continuations.
     */
    private function tokenise(string $command): array
    {
        $command = str_replace(["\\\n", "\\\r\n", "^\n"], ' ', trim($command));

        $tokens = [];
        $current = '';
        $quote = null;
        $has = false;

        for ($i = 0; $i < strlen($command); $i++) {
            $char = $command[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;

                    continue;
                }
                // Only double quotes process escapes, as in a POSIX shell.
                if ($char === '\\' && $quote === '"' && isset($command[$i + 1])) {
                    $current .= $command[++$i];

                    continue;
                }
                $current .= $char;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $has = true;

                continue;
            }

            if ($char === '\\' && isset($command[$i + 1])) {
                $current .= $command[++$i];
                $has = true;

                continue;
            }

            if (preg_match('/\s/', $char)) {
                if ($current !== '' || $has) {
                    $tokens[] = $current;
                    $current = '';
                    $has = false;
                }

                continue;
            }

            $current .= $char;
            $has = true;
        }

        if ($current !== '' || $has) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    private function splitHeader(string $raw): array
    {
        $position = strpos($raw, ':');

        if ($position === false) {
            return [null, null];
        }

        $name = trim(substr($raw, 0, $position));
        $value = trim(substr($raw, $position + 1));

        return [$name === '' ? null : $name, $value];
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeJson(string $body): bool
    {
        $trimmed = ltrim($body);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    /**
     * Devtools wraps URLs in quotes and sometimes appends a location fragment.
     */
    private function cleanUrl(string $url): string
    {
        return trim($url, "'\" \t\n\r");
    }
}
