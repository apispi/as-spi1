<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Collections\RequestExecutor;
use App\Services\Diff\GraphqlSchemaDiffer;
use App\Services\Diff\OpenApiDiffer;
use App\Services\Graphql\GraphqlIntrospector;
use App\Services\Import\ImportException;
use Illuminate\Http\Request;

/**
 * Diff two OpenAPI documents and flag breaking changes. Persisted as a
 * shareable report so a spec review can be linked into a PR, and returned with
 * a 422 when breaking changes are found so a CI job can gate a merge on it.
 */
class ApiDiffController extends Controller
{
    public function openapi(Request $request, OpenApiDiffer $differ)
    {
        $validated = $request->validate([
            'old' => 'required|string|max:2000000',
            'new' => 'required|string|max:2000000',
        ], [
            'old.required' => 'Provide the previous (baseline) OpenAPI document.',
            'new.required' => 'Provide the new OpenAPI document to compare against.',
        ]);

        try {
            $result = $differ->diff($validated['old'], $validated['new']);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'api_diff',
            'summary' => sprintf(
                '%s: %s',
                $result['new_title'],
                $result['breaking']
                    ? $result['breaking_count'].' breaking change'.($result['breaking_count'] === 1 ? '' : 's')
                    : 'no breaking changes'
            ),
            'data' => $result,
        ]);

        // 422 when breaking, so `spi diff … || exit 1` gates a pipeline; the
        // body still carries the full diff either way.
        return response()->json($result + ['report_id' => $report->id], $result['breaking'] ? 422 : 200);
    }

    /**
     * Diff two GraphQL schemas.
     *
     * Each side is either an introspection document or a live endpoint to
     * introspect, so the common case — "does staging still match production?"
     * — does not require fetching two JSON blobs by hand first.
     */
    public function graphql(Request $request, GraphqlSchemaDiffer $differ)
    {
        $validated = $request->validate([
            'old' => 'nullable|string|max:8000000',
            'new' => 'nullable|string|max:8000000',
            'old_url' => ['nullable', 'string', 'max:2048', new PubliclyRoutableUrl],
            'new_url' => ['nullable', 'string', 'max:2048', new PubliclyRoutableUrl],
            'headers' => 'nullable|array',
        ]);

        try {
            $old = $this->graphqlSide($validated, 'old');
            $new = $this->graphqlSide($validated, 'new');
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $result = $differ->diff($old, $new);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $label = $validated['new_url'] ?? 'GraphQL schema';

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'graphql_diff',
            'summary' => sprintf(
                '%s: %s',
                $label,
                $result['breaking']
                    ? $result['breaking_count'].' breaking change'.($result['breaking_count'] === 1 ? '' : 's')
                    : 'no breaking changes'
            ),
            'data' => $result + ['old_source' => $this->sourceLabel($validated, 'old'), 'new_source' => $this->sourceLabel($validated, 'new')],
        ]);

        return response()->json($result + ['report_id' => $report->id], $result['breaking'] ? 422 : 200);
    }

    /**
     * One side of a GraphQL diff: the pasted document, or the result of
     * introspecting the given endpoint.
     */
    private function graphqlSide(array $validated, string $side): string|array
    {
        $document = trim((string) ($validated[$side] ?? ''));

        if ($document !== '') {
            return $document;
        }

        $url = $validated[$side.'_url'] ?? null;

        if (! $url) {
            throw new ImportException("Provide the {$side} schema — paste an introspection document, or give an endpoint URL to introspect.");
        }

        return $this->introspect($url, $validated['headers'] ?? [], $side);
    }

    /** Run the diff-grade introspection query against a live endpoint. */
    private function introspect(string $url, array $headers, string $side): array
    {
        $response = app(RequestExecutor::class)->send([
            'protocol' => 'rest',
            'method' => 'POST',
            'url' => $url,
            'headers' => array_merge($headers, [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            'body' => json_encode(['query' => GraphqlIntrospector::DIFF_QUERY]),
        ]);

        if (! ($response['ok'] ?? false) || ($response['status'] ?? 0) >= 400) {
            throw new ImportException(
                'Introspecting the '.$side.' endpoint returned '.($response['status'] ?? 'no response').'. '.($response['error'] ?? '')
            );
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $schema = $decoded['data']['__schema'] ?? null;

        if (! is_array($schema)) {
            throw new ImportException(
                isset($decoded['errors'][0]['message'])
                    ? 'Introspecting the '.$side.' endpoint failed: '.$decoded['errors'][0]['message']
                    : 'The '.$side.' endpoint did not return a schema. Introspection may be disabled.'
            );
        }

        return $schema;
    }

    private function sourceLabel(array $validated, string $side): string
    {
        return $validated[$side.'_url'] ?? 'pasted document';
    }
}
