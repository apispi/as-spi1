<?php

namespace App\Services\Export;

use App\Models\Collection;

/**
 * Exports a Spi collection as a Postman Collection v2.1 document.
 *
 * The fit is unusually clean: Postman uses the same `{{variable}}` syntax Spi
 * does, so environments carry across untouched. The extra mile is compiling
 * each step's assertions into a Postman test script — a Spi suite arrives in
 * Postman already asserting, not just as bare requests.
 */
class PostmanExporter
{
    public function export(Collection $collection): array
    {
        $collection->loadMissing('steps.savedRequest');

        $items = [];
        foreach ($collection->steps as $step) {
            $saved = $step->savedRequest;
            if (! $saved) {
                continue;
            }
            $items[] = $this->item($saved);
        }

        return [
            'info' => [
                'name' => $collection->name,
                'description' => $collection->description ?? 'Exported from Spi (apispi.com).',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_postman_id' => (string) \Illuminate\Support\Str::uuid(),
            ],
            'item' => $items,
        ];
    }

    private function item($saved): array
    {
        $item = [
            'name' => $saved->name,
            'request' => [
                'method' => strtoupper($saved->method ?: 'GET'),
                'header' => $this->headers($saved->headers ?? []),
                'url' => $this->url($saved->url),
            ],
        ];

        if ($saved->body !== null && $saved->body !== '') {
            $item['request']['body'] = [
                'mode' => 'raw',
                'raw' => $saved->body,
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        $script = $this->testScript($saved->assertions ?? []);
        if ($script !== []) {
            $item['event'] = [[
                'listen' => 'test',
                'script' => ['type' => 'text/javascript', 'exec' => $script],
            ]];
        }

        return $item;
    }

    private function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if ($key === '' || $key === null) {
                continue;
            }
            $out[] = ['key' => (string) $key, 'value' => is_array($value) ? implode(', ', $value) : (string) $value];
        }

        return $out;
    }

    /**
     * Postman wants a structured url; {{variables}} survive in raw + host/path.
     */
    private function url(string $url): array
    {
        $parts = parse_url($url);
        $structured = ['raw' => $url];

        if (isset($parts['scheme'])) {
            $structured['protocol'] = $parts['scheme'];
        }
        if (isset($parts['host'])) {
            $structured['host'] = explode('.', $parts['host']);
        } elseif (str_contains($url, '{{')) {
            // A URL that is all variable (e.g. https://{{base_url}}/x) parses
            // oddly; keep the raw and a best-effort host token.
            $structured['host'] = [preg_replace('#^\w+://#', '', explode('/', $url)[2] ?? $url)];
        }
        if (! empty($parts['path'])) {
            $structured['path'] = array_values(array_filter(explode('/', $parts['path'])));
        }
        if (isset($parts['query'])) {
            $structured['query'] = $this->query($parts['query']);
        }

        return $structured;
    }

    private function query(string $query): array
    {
        $out = [];
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
            $out[] = ['key' => urldecode((string) $k), 'value' => $v === null ? null : urldecode($v)];
        }

        return $out;
    }

    /**
     * Compile Spi assertions into Postman pm.test lines. Unmappable rows are
     * skipped rather than emitting broken JS.
     *
     * @return array<int,string> lines of the script
     */
    private function testScript(array $assertions): array
    {
        if ($assertions === []) {
            return [];
        }

        $lines = ['const body = (() => { try { return pm.response.json(); } catch (e) { return null; } })();', ''];

        foreach ($assertions as $a) {
            $line = $this->assertionLine($a['path'] ?? '', $a['operator'] ?? '', $a['expected'] ?? null);
            if ($line !== null) {
                $name = addslashes($a['description'] ?? (($a['path'] ?? '?').' '.($a['operator'] ?? '')));
                $lines[] = "pm.test(\"{$name}\", function () { {$line} });";
            }
        }

        return count($lines) > 2 ? $lines : [];
    }

    private function assertionLine(string $path, string $operator, mixed $expected): ?string
    {
        // Left-hand accessor for the value under test.
        $accessor = match (true) {
            $path === 'status' => 'pm.response.code',
            $path === 'time_ms' => 'pm.response.responseTime',
            str_starts_with($path, 'header.') => "pm.response.headers.get(".json_encode(substr($path, 7)).")",
            default => 'pm.expect('.$this->bodyAccessor($path).')',
        };

        $isExpect = str_starts_with($accessor, 'pm.expect');
        $lhs = $isExpect ? $accessor : "pm.expect({$accessor})";
        $exp = json_encode($expected);

        return match ($operator) {
            'equals' => "{$lhs}.to.eql({$this->coerce($path, $expected)});",
            'not_equals' => "{$lhs}.to.not.eql({$this->coerce($path, $expected)});",
            'contains' => "{$lhs}.to.include({$exp});",
            'not_contains' => "{$lhs}.to.not.include({$exp});",
            'exists' => "{$lhs}.to.not.be.undefined;",
            'not_exists' => "{$lhs}.to.be.undefined;",
            'greater_than' => "{$lhs}.to.be.above({$this->num($expected)});",
            'greater_or_equal' => "{$lhs}.to.be.at.least({$this->num($expected)});",
            'less_than' => "{$lhs}.to.be.below({$this->num($expected)});",
            'less_or_equal' => "{$lhs}.to.be.at.most({$this->num($expected)});",
            'matches' => "{$lhs}.to.match(new RegExp({$exp}));",
            'has_length' => "{$lhs}.to.have.lengthOf({$this->num($expected)});",
            'is_type' => $this->typeLine($accessor, (string) $expected),
            default => null,
        };
    }

    /** Dot/`$.`/bracket path → a JS member expression on `body`. */
    private function bodyAccessor(string $path): string
    {
        $normalised = str_replace(['[', ']'], ['.', ''], preg_replace('/^\$\.?/', '', $path));
        $expr = 'body';
        foreach (array_filter(explode('.', $normalised), fn ($s) => $s !== '') as $seg) {
            $expr .= ctype_digit($seg) ? "[{$seg}]" : '['.json_encode($seg).']';
        }

        return $expr;
    }

    /** status/time compare as numbers; body values keep their JSON type. */
    private function coerce(string $path, mixed $expected): string
    {
        if (in_array($path, ['status', 'time_ms'], true) || str_starts_with($path, 'header.')) {
            return is_numeric($expected) && ! str_starts_with($path, 'header.') ? (string) (0 + $expected) : json_encode((string) $expected);
        }

        return is_numeric($expected) ? (string) (0 + $expected) : json_encode($expected);
    }

    private function num(mixed $expected): string
    {
        return is_numeric($expected) ? (string) (0 + $expected) : '0';
    }

    private function typeLine(string $accessor, string $type): string
    {
        $val = str_starts_with($accessor, 'pm.expect(') ? substr($accessor, 10, -1) : $accessor;

        return match ($type) {
            'array' => "pm.expect(Array.isArray({$val})).to.be.true;",
            'object' => "pm.expect({$val}).to.be.an('object');",
            'string' => "pm.expect({$val}).to.be.a('string');",
            'number' => "pm.expect({$val}).to.be.a('number');",
            'boolean' => "pm.expect({$val}).to.be.a('boolean');",
            'null' => "pm.expect({$val}).to.be.null;",
            default => "pm.expect({$val}).to.exist;",
        };
    }
}
