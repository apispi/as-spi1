<?php

namespace App\Services\Export;

use App\Models\Collection;
use App\Models\SavedRequest;
use App\Services\Contracts\SchemaInferrer;
use Illuminate\Support\Str;

/**
 * Exports a Spi collection as an OpenAPI 3.1 document.
 *
 * Each HTTP saved request becomes a path + operation. The payoff over the
 * Postman export is that Spi already holds structural knowledge the OpenAPI
 * format wants: an inferred response contract becomes the operation's response
 * schema, and a JSON request body is inferred into a requestBody schema. So a
 * Spi suite arrives in Swagger UI / a code generator already describing its
 * shapes, not just its URLs.
 *
 * Spi's `{{var}}` templating is mapped to OpenAPI's single-brace `{var}`:
 * variables in the origin become server variables, variables in the path
 * become path parameters.
 */
class OpenApiExporter
{
    /** Methods that carry a request body. */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private SchemaInferrer $inferrer) {}

    public function export(Collection $collection): array
    {
        $collection->loadMissing('steps.savedRequest');

        $paths = [];
        $servers = [];
        $needsBearer = false;

        foreach ($collection->steps as $step) {
            $saved = $step->savedRequest;
            if (! $saved || ! $this->isHttp($saved)) {
                continue;
            }

            $method = strtolower($saved->method ?: 'get');
            [$serverUrl, $serverVars, $pathTemplate] = $this->splitUrl($saved->url ?? '');

            if ($serverUrl !== null) {
                $servers[$serverUrl] = $serverVars;
            }

            // First operation wins for a given path+method — OpenAPI cannot
            // hold two, and exact duplicates are rare in a real suite.
            if (isset($paths[$pathTemplate][$method])) {
                continue;
            }

            $operation = $this->operation($saved, $pathTemplate, $needsBearer);
            $paths[$pathTemplate][$method] = $operation;
        }

        $doc = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $collection->name,
                'description' => $collection->description ?: 'Exported from Spi (apispi.com).',
                'version' => '1.0.0',
            ],
            'servers' => $this->serverList($servers),
            'paths' => $paths ?: new \stdClass,
        ];

        if ($needsBearer) {
            $doc['components'] = [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ];
            $doc['security'] = [['bearerAuth' => []]];
        }

        return $doc;
    }

    private function isHttp(SavedRequest $saved): bool
    {
        // The catalogue includes non-HTTP protocols (grpc, mqtt, …) that have
        // no OpenAPI representation; export only REST/HTTP.
        return in_array(strtolower((string) $saved->protocol), ['rest', 'http', '', 'graphql'], true);
    }

    /**
     * Split a request URL into an OpenAPI server URL (+ its server variables)
     * and a path template. `{{var}}` becomes `{var}` throughout.
     *
     * @return array{0: ?string, 1: array<string,array{default:string}>, 2: string}
     */
    private function splitUrl(string $url): array
    {
        $single = $this->toSingleBrace($url);
        $parts = parse_url($single);

        // A URL that is entirely a variable (https://{base_url}/x) can confuse
        // parse_url; fall back to a manual split on the third slash.
        if (! isset($parts['host']) && preg_match('#^(\w+)://([^/]+)(/.*)?$#', $single, $m)) {
            $parts = ['scheme' => $m[1], 'host' => $m[2], 'path' => $m[3] ?? '/'];
        }

        $serverUrl = null;
        $serverVars = [];
        if (isset($parts['host'])) {
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $serverUrl = $scheme.'://'.$host;
            foreach ($this->bracedNames($serverUrl) as $name) {
                $serverVars[$name] = ['default' => $name];
            }
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        return [$serverUrl, $serverVars, $path];
    }

    private function operation(SavedRequest $saved, string $pathTemplate, bool &$needsBearer): array
    {
        $parameters = [];

        // Path parameters, from {var} tokens in the templated path.
        foreach ($this->bracedNames($pathTemplate) as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        // Query parameters, from the URL query string and stored params.
        foreach ($this->queryKeys($saved) as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
            ];
        }

        // Header parameters; Authorization is modelled as bearer security.
        foreach ($saved->headers ?? [] as $key => $value) {
            $lower = strtolower((string) $key);
            if ($lower === 'authorization') {
                $needsBearer = true;

                continue;
            }
            if (in_array($lower, ['content-type', 'accept', ''], true)) {
                continue;
            }
            $parameters[] = [
                'name' => (string) $key,
                'in' => 'header',
                'required' => false,
                'schema' => ['type' => 'string'],
            ];
        }

        $operation = [
            'summary' => $saved->name,
            'operationId' => Str::camel($saved->name.' '.strtolower($saved->method ?: 'get')),
            'responses' => $this->responses($saved),
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $requestBody = $this->requestBody($saved);
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        return $operation;
    }

    private function requestBody(SavedRequest $saved): ?array
    {
        $body = $saved->body;
        if ($body === null || trim((string) $body) === '') {
            return null;
        }
        if (! in_array(strtoupper($saved->method ?: 'GET'), self::BODY_METHODS, true)) {
            return null;
        }

        // Infer from the JSON body when possible; otherwise describe it as text.
        $schema = $this->inferrer->fromBody($this->stripVars((string) $body));
        if ($schema !== null) {
            return [
                'required' => true,
                'content' => ['application/json' => ['schema' => $this->toOpenApiSchema($schema)]],
            ];
        }

        return [
            'required' => true,
            'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
        ];
    }

    private function responses(SavedRequest $saved): array
    {
        $status = (string) $this->expectedStatus($saved);

        $response = ['description' => $status[0] === '2' ? 'Successful response' : 'Response'];

        if (is_array($saved->contract) && $saved->contract !== []) {
            $response['content'] = [
                'application/json' => ['schema' => $this->toOpenApiSchema($saved->contract)],
            ];
        }

        return [$status => $response];
    }

    /** Use a `status equals N` assertion as the documented response code. */
    private function expectedStatus(SavedRequest $saved): int
    {
        foreach ($saved->assertions ?? [] as $a) {
            if (($a['path'] ?? null) === 'status' && ($a['operator'] ?? null) === 'equals' && is_numeric($a['expected'] ?? null)) {
                return (int) $a['expected'];
            }
        }

        return 200;
    }

    /**
     * @return array<int,string>
     */
    private function queryKeys(SavedRequest $saved): array
    {
        $keys = [];

        $query = parse_url($this->toSingleBrace($saved->url ?? ''), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            foreach (explode('&', $query) as $pair) {
                $k = urldecode(explode('=', $pair, 2)[0]);
                if ($k !== '' && ! $this->isBraced($k)) {
                    $keys[$k] = true;
                }
            }
        }

        if (is_array($saved->params)) {
            foreach ($saved->params as $key => $value) {
                // params may be [{key,value}] or {key: value}.
                $name = is_array($value) ? ($value['key'] ?? null) : $key;
                if (is_string($name) && $name !== '') {
                    $keys[$name] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Convert an inferred/stored contract schema to a valid OpenAPI 3.1 schema.
     * The shapes are nearly identical; the only fixes are dropping the internal
     * `unknown` type and pruning empty `required`.
     */
    private function toOpenApiSchema(array $schema): array
    {
        $out = [];

        if (isset($schema['type'])) {
            $type = $schema['type'];
            $types = array_values(array_filter((array) $type, fn ($t) => $t !== 'unknown'));
            if ($types !== []) {
                $out['type'] = count($types) === 1 ? $types[0] : $types;
            }
        }

        if (isset($schema['format'])) {
            $out['format'] = $schema['format'];
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $out['properties'] = [];
            foreach ($schema['properties'] as $name => $sub) {
                $out['properties'][$name] = is_array($sub) ? $this->toOpenApiSchema($sub) : [];
            }
        }

        if (! empty($schema['required']) && is_array($schema['required'])) {
            $out['required'] = array_values($schema['required']);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $out['items'] = $this->toOpenApiSchema($schema['items']);
        }

        return $out === [] ? new \stdClass : $out; // empty schema = {}
    }

    /**
     * @param array<string,array<string,array{default:string}>> $servers
     * @return array<int,array<string,mixed>>
     */
    private function serverList(array $servers): array
    {
        if ($servers === []) {
            return [['url' => 'https://api.example.com']];
        }

        $list = [];
        foreach ($servers as $url => $vars) {
            $entry = ['url' => $url];
            if ($vars !== []) {
                $entry['variables'] = $vars;
            }
            $list[] = $entry;
        }

        return $list;
    }

    /** Spi `{{var}}` → OpenAPI `{var}`. */
    private function toSingleBrace(string $s): string
    {
        return preg_replace('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', '{$1}', $s) ?? $s;
    }

    /** Remove `{{var}}` tokens entirely — for JSON body parsing. */
    private function stripVars(string $s): string
    {
        return preg_replace('/\{\{\s*[A-Za-z0-9_.-]+\s*\}\}/', '', $s) ?? $s;
    }

    /** @return array<int,string> distinct `{name}` tokens, in order. */
    private function bracedNames(string $s): array
    {
        preg_match_all('/\{([A-Za-z0-9_.-]+)\}/', $s, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    private function isBraced(string $s): bool
    {
        return (bool) preg_match('/^\{[A-Za-z0-9_.-]+\}$/', $s);
    }
}
