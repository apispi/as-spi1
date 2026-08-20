<?php

namespace App\Services\Import;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns an OpenAPI 3 document into requests Spi can run.
 *
 * Server URLs become {{base_url}} rather than being baked in, so an imported
 * collection can be pointed at staging or production by switching
 * environment — which is the whole reason to import into this app rather than
 * just reading the spec.
 *
 * Path and required query parameters become {{placeholders}} too, so an
 * imported request states what it needs instead of silently sending "string".
 */
class OpenApiImporter
{
    /** Operations imported from one document. */
    public const MAX_OPERATIONS = 100;

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * @return array{title: string, base_url: string|null, operations: array, warnings: array}
     */
    public function parse(string $document): array
    {
        $spec = $this->decode($document);

        if (! is_array($spec)) {
            throw new ImportException('The document did not parse into an object.');
        }

        if (! isset($spec['openapi']) && ! isset($spec['swagger'])) {
            throw new ImportException('Not an OpenAPI document (no "openapi" or "swagger" key).');
        }

        if (isset($spec['swagger'])) {
            throw new ImportException('Swagger 2.0 is not supported — convert the document to OpenAPI 3 first.');
        }

        $paths = $spec['paths'] ?? [];

        if (! is_array($paths) || $paths === []) {
            throw new ImportException('The document defines no paths.');
        }

        $warnings = [];
        $operations = [];

        foreach ($paths as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            // Parameters can be declared once for every method on a path.
            $shared = $item['parameters'] ?? [];

            foreach (self::METHODS as $method) {
                if (! isset($item[$method]) || ! is_array($item[$method])) {
                    continue;
                }

                if (count($operations) >= self::MAX_OPERATIONS) {
                    $warnings[] = sprintf(
                        'Only the first %d operations were imported.',
                        self::MAX_OPERATIONS
                    );
                    break 2;
                }

                $operations[] = $this->operation($path, $method, $item[$method], $shared);
            }
        }

        if ($operations === []) {
            throw new ImportException('No operations found in the document.');
        }

        return [
            'title' => $spec['info']['title'] ?? 'Imported API',
            'base_url' => $this->baseUrl($spec),
            'operations' => $operations,
            'warnings' => $warnings,
        ];
    }

    private function decode(string $document): mixed
    {
        $document = trim($document);

        if ($document === '') {
            throw new ImportException('The document is empty.');
        }

        if (str_starts_with($document, '{')) {
            $decoded = json_decode($document, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ImportException('Invalid JSON: '.json_last_error_msg());
            }

            return $decoded;
        }

        try {
            return Yaml::parse($document);
        } catch (ParseException $e) {
            throw new ImportException('Invalid YAML: '.$e->getMessage());
        }
    }

    /**
     * The first server URL, if the document declares one.
     */
    private function baseUrl(array $spec): ?string
    {
        $url = $spec['servers'][0]['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return rtrim($url, '/');
    }

    private function operation(string $path, string $method, array $operation, array $shared): array
    {
        $parameters = array_merge(
            is_array($shared) ? $shared : [],
            is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []
        );

        // {petId} in the spec becomes {{petId}} for Spi, so it resolves from
        // the environment like any other variable.
        $url = '{{base_url}}'.preg_replace('/\{([^}]+)\}/', '{{$1}}', $path);

        $query = [];
        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }
            if (($parameter['in'] ?? null) === 'query' && ! empty($parameter['required'])) {
                $name = $parameter['name'] ?? null;
                if ($name) {
                    $query[] = urlencode($name).'={{'.$name.'}}';
                }
            }
        }

        if ($query !== []) {
            $url .= '?'.implode('&', $query);
        }

        $headers = [];
        foreach ($parameters as $parameter) {
            if (is_array($parameter) && ($parameter['in'] ?? null) === 'header' && ! empty($parameter['required'])) {
                $name = $parameter['name'] ?? null;
                if ($name) {
                    $headers[$name] = '{{'.$name.'}}';
                }
            }
        }

        $body = $this->body($operation, $headers);

        return [
            'name' => $this->name($operation, $method, $path),
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'assertions' => $this->assertions($operation),
        ];
    }

    /**
     * Build an example body from the request schema, so a POST arrives ready
     * to edit rather than empty.
     */
    private function body(array $operation, array &$headers): ?string
    {
        $content = $operation['requestBody']['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return null;
        }

        if (isset($content['application/json'])) {
            $media = $content['application/json'];
            $headers['Content-Type'] = 'application/json';

            $example = $media['example']
                ?? $media['examples'][array_key_first($media['examples'] ?? [])]['value']
                ?? $this->fromSchema($media['schema'] ?? null);

            return $example === null
                ? null
                : json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $headers['Content-Type'] = array_key_first($content);

        return null;
    }

    /**
     * A minimal instance of a schema. $ref is not resolved — a placeholder is
     * clearer than a wrong guess at the referenced shape.
     */
    private function fromSchema(mixed $schema, int $depth = 0): mixed
    {
        if (! is_array($schema) || $depth > 4) {
            return null;
        }

        if (isset($schema['example'])) {
            return $schema['example'];
        }

        if (isset($schema['$ref'])) {
            return '{{'.basename((string) $schema['$ref']).'}}';
        }

        return match ($schema['type'] ?? null) {
            'object' => collect($schema['properties'] ?? [])
                ->map(fn ($property) => $this->fromSchema($property, $depth + 1))
                ->all() ?: new \stdClass,
            'array' => [$this->fromSchema($schema['items'] ?? null, $depth + 1)],
            'string' => $schema['default'] ?? ($schema['enum'][0] ?? ''),
            'integer', 'number' => $schema['default'] ?? 0,
            'boolean' => $schema['default'] ?? false,
            default => null,
        };
    }

    /**
     * Assertions derived from the documented success response.
     *
     * Only what the spec actually promises: the status code, and the type of
     * the top-level body. Inventing field-level checks from a schema produces
     * assertions that fail against a healthy API.
     */
    private function assertions(array $operation): array
    {
        $responses = $operation['responses'] ?? [];

        if (! is_array($responses)) {
            return [];
        }

        $success = null;
        foreach (array_keys($responses) as $code) {
            if (is_numeric($code) && (int) $code >= 200 && (int) $code < 300) {
                $success = (int) $code;
                break;
            }
        }

        if ($success === null) {
            return [];
        }

        $assertions = [[
            'path' => 'status',
            'operator' => 'equals',
            'expected' => (string) $success,
            'description' => 'Documented success status',
        ]];

        $schema = $responses[(string) $success]['content']['application/json']['schema'] ?? null;

        if (is_array($schema) && in_array($schema['type'] ?? null, ['object', 'array'], true)) {
            $assertions[] = [
                'path' => '$',
                'operator' => 'is_type',
                'expected' => $schema['type'],
                'description' => 'Documented response type',
            ];
        }

        return $assertions;
    }

    private function name(array $operation, string $method, string $path): string
    {
        $name = $operation['summary'] ?? $operation['operationId'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return mb_substr(trim($name), 0, 255);
        }

        return strtoupper($method).' '.$path;
    }
}
