<?php

namespace App\Services\Import;

/**
 * Parses a Postman Collection v2.x document into the normalised shape the
 * importer shares with OpenApiImporter: { title, base_url, operations, warnings }.
 *
 * The fit is close — Postman uses the same {{variable}} syntax Spi does — so
 * variables carry across untouched. Folders are flattened; each request item
 * becomes one operation.
 */
class PostmanImporter
{
    public function parse(string $document): array
    {
        $document = trim($document);
        if ($document === '') {
            throw new ImportException('The document is empty.');
        }

        $data = json_decode($document, true);
        if (! is_array($data)) {
            throw new ImportException('That is not valid JSON.');
        }
        if (! isset($data['item']) || ! is_array($data['item'])) {
            throw new ImportException('This does not look like a Postman collection (no "item" array).');
        }

        $warnings = [];
        $operations = [];
        $this->walk($data['item'], $operations, $warnings);

        if ($operations === []) {
            throw new ImportException('No requests were found in the collection.');
        }

        if (! empty($data['auth'])) {
            $warnings[] = 'Collection-level auth was not imported — add it as a header or {{variable}}.';
        }

        return [
            'title' => $data['info']['name'] ?? 'Imported collection',
            'base_url' => null,
            'operations' => $operations,
            'warnings' => $warnings,
        ];
    }

    /** Recursively flatten folders, collecting request items. */
    private function walk(array $items, array &$operations, array &$warnings): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            // A folder: recurse into its own item list.
            if (isset($item['item']) && is_array($item['item'])) {
                $this->walk($item['item'], $operations, $warnings);

                continue;
            }

            if (! isset($item['request'])) {
                continue;
            }

            $op = $this->operation($item);
            if ($op === null) {
                $warnings[] = sprintf('Skipped "%s" — it has no usable URL.', $item['name'] ?? 'a request');

                continue;
            }
            $operations[] = $op;
        }
    }

    private function operation(array $item): ?array
    {
        $request = $item['request'];

        // A request can be just a URL string.
        if (is_string($request)) {
            $request = ['method' => 'GET', 'url' => $request];
        }
        if (! is_array($request)) {
            return null;
        }

        $url = $this->url($request['url'] ?? null);
        if ($url === '') {
            return null;
        }

        return [
            'name' => (string) ($item['name'] ?? 'Request'),
            'method' => strtoupper((string) ($request['method'] ?? 'GET')),
            'url' => $url,
            'headers' => $this->headers($request['header'] ?? []),
            'body' => $this->body($request['body'] ?? null),
            'assertions' => [],
        ];
    }

    private function url(mixed $url): string
    {
        if (is_string($url)) {
            return trim($url);
        }
        if (! is_array($url)) {
            return '';
        }
        if (! empty($url['raw'])) {
            return (string) $url['raw'];
        }

        // Reconstruct from parts when there is no raw form.
        $host = is_array($url['host'] ?? null) ? implode('.', $url['host']) : (string) ($url['host'] ?? '');
        $path = is_array($url['path'] ?? null) ? implode('/', $url['path']) : ltrim((string) ($url['path'] ?? ''), '/');
        $out = $host !== '' ? $host.'/'.$path : $path;

        if (! empty($url['query']) && is_array($url['query'])) {
            $pairs = [];
            foreach ($url['query'] as $q) {
                if (! empty($q['disabled'])) {
                    continue;
                }
                $pairs[] = rawurlencode((string) ($q['key'] ?? '')).'='.rawurlencode((string) ($q['value'] ?? ''));
            }
            if ($pairs) {
                $out .= '?'.implode('&', $pairs);
            }
        }

        return trim($out);
    }

    /** @return array<string,string> */
    private function headers(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }
        $out = [];
        foreach ($headers as $h) {
            if (! is_array($h) || ! empty($h['disabled']) || ($h['key'] ?? '') === '') {
                continue;
            }
            $out[(string) $h['key']] = (string) ($h['value'] ?? '');
        }

        return $out;
    }

    private function body(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        return match ($body['mode'] ?? null) {
            'raw' => ($body['raw'] ?? '') !== '' ? (string) $body['raw'] : null,
            'urlencoded' => $this->urlencoded($body['urlencoded'] ?? []),
            'graphql' => $this->graphql($body['graphql'] ?? []),
            default => null,
        };
    }

    private function urlencoded(mixed $rows): ?string
    {
        if (! is_array($rows)) {
            return null;
        }
        $pairs = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! empty($r['disabled'])) {
                continue;
            }
            $pairs[] = rawurlencode((string) ($r['key'] ?? '')).'='.rawurlencode((string) ($r['value'] ?? ''));
        }

        return $pairs ? implode('&', $pairs) : null;
    }

    private function graphql(mixed $gql): ?string
    {
        if (! is_array($gql) || empty($gql['query'])) {
            return null;
        }
        $payload = ['query' => (string) $gql['query']];
        if (! empty($gql['variables'])) {
            $decoded = json_decode((string) $gql['variables'], true);
            $payload['variables'] = $decoded ?? $gql['variables'];
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
