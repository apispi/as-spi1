<?php

namespace App\Http\Controllers;

use App\Rules\PubliclyRoutableUrl;
use App\Services\Collections\RequestExecutor;
use App\Services\Graphql\GraphqlIntrospector;
use Illuminate\Http\Request;

/**
 * Fetches a GraphQL schema by running the introspection query against an
 * endpoint, then returns a compact summary of its operations. Reuses the same
 * executor (and SSRF guard) as every other outbound test.
 */
class GraphqlIntrospectionController extends Controller
{
    public function introspect(Request $request, RequestExecutor $executor, GraphqlIntrospector $introspector)
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', new PubliclyRoutableUrl],
            'headers' => 'nullable|array',
        ]);

        $response = $executor->send([
            'protocol' => 'rest',
            'method' => 'POST',
            'url' => $validated['url'],
            'headers' => array_merge($validated['headers'] ?? [], [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            'body' => json_encode(['query' => GraphqlIntrospector::QUERY]),
        ]);

        if (! ($response['ok'] ?? false) || ($response['status'] ?? 0) >= 400) {
            return response()->json([
                'message' => 'The endpoint returned '.($response['status'] ?? 'no response').'. '.($response['error'] ?? ''),
            ], 422);
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $schema = $decoded['data']['__schema'] ?? null;

        if (! is_array($schema)) {
            $gqlError = $decoded['errors'][0]['message'] ?? null;

            return response()->json([
                'message' => $gqlError
                    ? 'Introspection failed: '.$gqlError
                    : 'That endpoint did not return a GraphQL schema. Introspection may be disabled.',
            ], 422);
        }

        return response()->json($introspector->summarise($schema) + [
            'url' => $validated['url'],
            'status' => $response['status'],
        ]);
    }
}
