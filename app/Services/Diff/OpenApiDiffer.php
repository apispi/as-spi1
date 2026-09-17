<?php

namespace App\Services\Diff;

use App\Services\Import\ImportException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Compares two OpenAPI 3 documents and classifies what changed between them,
 * from the perspective of an existing consumer of the API.
 *
 * "Breaking" means a change that could break a client already calling the old
 * version: an endpoint that disappeared, a new mandatory input, a documented
 * success response that is no longer promised. Adding an endpoint or an
 * optional input is safe, and reported as informational so a reviewer sees the
 * whole shape of the change — not only the alarming parts.
 *
 * Deep schema comparison (field-by-field types, enum values) is deliberately
 * out of scope: it is where an OpenAPI differ turns into a maintenance sink,
 * and the operation-level surface is what gates a CI pipeline in practice.
 */
class OpenApiDiffer
{
    public const SEVERITY_BREAKING = 'breaking';

    public const SEVERITY_NON_BREAKING = 'non_breaking';

    public const SEVERITY_INFO = 'info';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * @return array{
     *   old_title: string, new_title: string,
     *   old_version: ?string, new_version: ?string,
     *   breaking: bool, breaking_count: int, non_breaking_count: int, info_count: int,
     *   summary: string, changes: array<int, array{severity: string, category: string, operation: ?string, detail: string}>
     * }
     */
    public function diff(string $oldDocument, string $newDocument): array
    {
        $old = $this->load($oldDocument, 'old');
        $new = $this->load($newDocument, 'new');

        $oldOps = $this->operations($old);
        $newOps = $this->operations($new);

        $changes = [];

        // API metadata — informational, but worth surfacing at the top.
        $oldVersion = $this->stringOrNull($old['info']['version'] ?? null);
        $newVersion = $this->stringOrNull($new['info']['version'] ?? null);
        if ($oldVersion !== $newVersion) {
            $changes[] = $this->change(self::SEVERITY_INFO, 'version', null, sprintf(
                'API version changed from %s to %s',
                $oldVersion ?? '(none)',
                $newVersion ?? '(none)'
            ));
        }

        // Removed operations (breaking) and surviving ones (compared in detail).
        foreach ($oldOps as $key => $oldOp) {
            if (! isset($newOps[$key])) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'operation_removed', $key, 'Endpoint removed');

                continue;
            }

            $changes = array_merge($changes, $this->compareOperation($key, $oldOp, $newOps[$key]));
        }

        // Added operations (informational — safe for existing clients).
        foreach ($newOps as $key => $_) {
            if (! isset($oldOps[$key])) {
                $changes[] = $this->change(self::SEVERITY_INFO, 'operation_added', $key, 'New endpoint');
            }
        }

        // Breaking first, then non-breaking, then info — the order a reviewer
        // wants to read them in, and grouped by operation within each band.
        $order = [self::SEVERITY_BREAKING => 0, self::SEVERITY_NON_BREAKING => 1, self::SEVERITY_INFO => 2];
        usort($changes, function ($a, $b) use ($order) {
            return [$order[$a['severity']], $a['operation'] ?? '']
                <=> [$order[$b['severity']], $b['operation'] ?? ''];
        });

        $counts = array_count_values(array_column($changes, 'severity'));
        $breaking = $counts[self::SEVERITY_BREAKING] ?? 0;
        $nonBreaking = $counts[self::SEVERITY_NON_BREAKING] ?? 0;
        $info = $counts[self::SEVERITY_INFO] ?? 0;

        return [
            'old_title' => $this->stringOrNull($old['info']['title'] ?? null) ?? 'API',
            'new_title' => $this->stringOrNull($new['info']['title'] ?? null) ?? 'API',
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'breaking' => $breaking > 0,
            'breaking_count' => $breaking,
            'non_breaking_count' => $nonBreaking,
            'info_count' => $info,
            'summary' => sprintf('%d breaking, %d non-breaking, %d informational', $breaking, $nonBreaking, $info),
            'changes' => array_values($changes),
        ];
    }

    /**
     * @return array<int, array{severity: string, category: string, operation: ?string, detail: string}>
     */
    private function compareOperation(string $key, array $old, array $new): array
    {
        $changes = [];

        // Parameters, keyed by "in:name" so a query "id" and a path "id" don't
        // collide.
        $oldParams = $old['parameters'];
        $newParams = $new['parameters'];

        foreach ($newParams as $paramKey => $newParam) {
            $oldParam = $oldParams[$paramKey] ?? null;
            $label = $newParam['in'].' parameter "'.$newParam['name'].'"';

            if ($oldParam === null) {
                $changes[] = $newParam['required']
                    ? $this->change(self::SEVERITY_BREAKING, 'required_param_added', $key, 'New required '.$label)
                    : $this->change(self::SEVERITY_NON_BREAKING, 'optional_param_added', $key, 'New optional '.$label);

                continue;
            }

            if (! $oldParam['required'] && $newParam['required']) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'param_now_required', $key, ucfirst($label).' is now required');
            } elseif ($oldParam['required'] && ! $newParam['required']) {
                $changes[] = $this->change(self::SEVERITY_NON_BREAKING, 'param_now_optional', $key, ucfirst($label).' is now optional');
            }
        }

        foreach ($oldParams as $paramKey => $oldParam) {
            if (! isset($newParams[$paramKey])) {
                // Removing an input a client may still send is tolerated by
                // most servers; not breaking for existing callers.
                $label = $oldParam['in'].' parameter "'.$oldParam['name'].'"';
                $changes[] = $this->change(self::SEVERITY_NON_BREAKING, 'param_removed', $key, 'Removed '.$label);
            }
        }

        // Request body: gaining a required body breaks callers that sent none.
        if (! $old['body_required'] && $new['body_required']) {
            $changes[] = $this->change(self::SEVERITY_BREAKING, 'body_now_required', $key, 'Request body is now required');
        } elseif ($old['body_required'] && ! $new['body_required']) {
            $changes[] = $this->change(self::SEVERITY_NON_BREAKING, 'body_now_optional', $key, 'Request body is now optional');
        } elseif (! $old['has_body'] && $new['has_body'] && ! $new['body_required']) {
            $changes[] = $this->change(self::SEVERITY_NON_BREAKING, 'body_added', $key, 'Request body added (optional)');
        }

        // Success responses: a 2xx the API used to promise and no longer does
        // is breaking; a new response code is informational.
        foreach ($old['success_codes'] as $code) {
            if (! in_array($code, $new['success_codes'], true)) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'success_response_removed', $key, 'Success response '.$code.' removed');
            }
        }
        foreach ($new['responses'] as $code) {
            if (! in_array($code, $old['responses'], true)) {
                $changes[] = $this->change(self::SEVERITY_INFO, 'response_added', $key, 'New response '.$code);
            }
        }

        return $changes;
    }

    /**
     * Flatten a spec's paths into operations keyed by "METHOD /path".
     *
     * @return array<string, array>
     */
    private function operations(array $spec): array
    {
        $paths = $spec['paths'] ?? [];
        if (! is_array($paths)) {
            return [];
        }

        $operations = [];

        foreach ($paths as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            $shared = is_array($item['parameters'] ?? null) ? $item['parameters'] : [];

            foreach (self::METHODS as $method) {
                if (! isset($item[$method]) || ! is_array($item[$method])) {
                    continue;
                }

                $key = strtoupper($method).' '.$path;
                $operations[$key] = $this->summariseOperation($spec, $item[$method], $shared);
            }
        }

        return $operations;
    }

    private function summariseOperation(array $spec, array $operation, array $shared): array
    {
        $merged = array_merge(
            $shared,
            is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []
        );

        $parameters = [];
        foreach ($merged as $parameter) {
            $parameter = $this->resolveRef($spec, $parameter);
            if (! is_array($parameter) || ! isset($parameter['name'], $parameter['in'])) {
                continue;
            }
            $in = (string) $parameter['in'];
            $name = (string) $parameter['name'];
            $parameters[$in.':'.$name] = [
                'in' => $in,
                'name' => $name,
                // Path parameters are required by definition in OpenAPI.
                'required' => $in === 'path' ? true : ! empty($parameter['required']),
            ];
        }

        $responses = [];
        $successCodes = [];
        foreach (array_keys($operation['responses'] ?? []) as $code) {
            $code = (string) $code;
            $responses[] = $code;
            if (is_numeric($code) && (int) $code >= 200 && (int) $code < 300) {
                $successCodes[] = $code;
            }
        }

        $body = $this->resolveRef($spec, $operation['requestBody'] ?? null);
        $hasBody = is_array($body) && ! empty($body['content']);

        return [
            'parameters' => $parameters,
            'has_body' => $hasBody,
            'body_required' => $hasBody && ! empty($body['required']),
            'responses' => $responses,
            'success_codes' => $successCodes,
        ];
    }

    /**
     * Resolve a local `$ref` (e.g. #/components/parameters/PageParam) one level.
     * A ref that points outside the document, or nests further, is left as-is —
     * the differ then simply cannot compare its internals, which is safe.
     */
    private function resolveRef(array $spec, mixed $node): mixed
    {
        if (! is_array($node) || ! isset($node['$ref']) || ! is_string($node['$ref'])) {
            return $node;
        }

        $ref = $node['$ref'];
        if (! str_starts_with($ref, '#/')) {
            return $node;
        }

        $segments = explode('/', ltrim($ref, '#/'));
        $target = $spec;
        foreach ($segments as $segment) {
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return $node;
            }
            $target = $target[$segment];
        }

        return is_array($target) ? $target : $node;
    }

    private function load(string $document, string $which): array
    {
        $spec = $this->decode($document, $which);

        if (! is_array($spec)) {
            throw new ImportException("The {$which} document did not parse into an object.");
        }

        if (! isset($spec['openapi'])) {
            if (isset($spec['swagger'])) {
                throw new ImportException("The {$which} document is Swagger 2.0 — convert it to OpenAPI 3 first.");
            }
            throw new ImportException("The {$which} document is not an OpenAPI 3 document (no \"openapi\" key).");
        }

        if (! isset($spec['paths']) || ! is_array($spec['paths']) || $spec['paths'] === []) {
            throw new ImportException("The {$which} document defines no paths.");
        }

        return $spec;
    }

    private function decode(string $document, string $which): mixed
    {
        $document = trim($document);

        if ($document === '') {
            throw new ImportException("The {$which} document is empty.");
        }

        if (str_starts_with($document, '{')) {
            $decoded = json_decode($document, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ImportException("Invalid JSON in the {$which} document: ".json_last_error_msg());
            }

            return $decoded;
        }

        try {
            return Yaml::parse($document);
        } catch (ParseException $e) {
            throw new ImportException("Invalid YAML in the {$which} document: ".$e->getMessage());
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) || is_numeric($value)) && (string) $value !== '' ? (string) $value : null;
    }

    private function change(string $severity, string $category, ?string $operation, string $detail): array
    {
        return compact('severity', 'category', 'operation', 'detail');
    }
}
