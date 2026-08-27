<?php

namespace App\Http\Controllers;

use App\Models\SavedRequest;
use App\Services\Export\RequestExporter;
use App\Services\Import\CurlImporter;
use App\Services\Import\ImportException;
use App\Services\Import\OpenApiImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImportController extends Controller
{
    /**
     * Parse a curl command into a request, without saving it.
     *
     * Preview-only by design: the tester fills its fields from this, and the
     * user saves through the normal path if they want to keep it.
     */
    public function curl(Request $request, CurlImporter $importer)
    {
        $validated = $request->validate([
            'command' => 'required|string|max:20000',
        ]);

        try {
            return response()->json($importer->parse($validated['command']));
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Turn an OpenAPI document into saved requests, optionally grouped into a
     * collection and paired with an environment holding the server URL.
     */
    public function openapi(Request $request, OpenApiImporter $importer)
    {
        $validated = $request->validate([
            'document' => 'required|string|max:2000000',
            'create_collection' => 'nullable|boolean',
            'create_environment' => 'nullable|boolean',
        ]);

        $user = $request->user();

        try {
            $parsed = $importer->parse($validated['document']);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $remaining = $this->remainingQuota($user);

        if ($remaining <= 0) {
            return response()->json([
                'message' => 'Saved-request limit reached ('.SavedRequestController::FREE_PLAN_LIMIT.'). Delete some to import.',
            ], 422);
        }

        $operations = array_slice($parsed['operations'], 0, $remaining);
        $warnings = $parsed['warnings'];

        if (count($operations) < count($parsed['operations'])) {
            $warnings[] = sprintf(
                'Imported %d of %d operations — the rest would exceed your saved-request limit.',
                count($operations),
                count($parsed['operations'])
            );
        }

        // All-or-nothing: a half-imported spec is worse than a clear failure.
        $result = DB::transaction(function () use ($user, $parsed, $operations, $validated) {
            $created = [];

            foreach ($operations as $operation) {
                $created[] = $user->savedRequests()->create([
                    'name' => $this->uniqueName($user, $operation['name']),
                    'protocol' => 'rest',
                    'method' => $operation['method'],
                    'url' => $operation['url'],
                    'headers' => $operation['headers'] ?: null,
                    'body' => $operation['body'],
                    'assertions' => $operation['assertions'] ?: null,
                ]);
            }

            $collection = null;
            if (! empty($validated['create_collection'])) {
                $collection = $user->collections()->create([
                    'name' => $this->uniqueCollectionName($user, $parsed['title']),
                    'description' => 'Imported from OpenAPI',
                ]);

                foreach ($created as $position => $saved) {
                    $collection->steps()->create([
                        'saved_request_id' => $saved->id,
                        'position' => $position,
                    ]);
                }
            }

            $environment = null;
            if (! empty($validated['create_environment']) && $parsed['base_url']) {
                $environment = $user->environments()->create([
                    'name' => $this->uniqueEnvironmentName($user, $parsed['title']),
                    'variables' => [[
                        'key' => 'base_url',
                        'value' => $parsed['base_url'],
                        'secret' => false,
                    ]],
                ]);
            }

            return compact('created', 'collection', 'environment');
        });

        return response()->json([
            'title' => $parsed['title'],
            'base_url' => $parsed['base_url'],
            'imported' => count($result['created']),
            'requests' => $result['created'],
            'collection' => $result['collection'],
            'environment' => $result['environment'],
            'warnings' => $warnings,
        ], 201);
    }

    /**
     * Render a saved request as a runnable snippet.
     */
    public function export(Request $request, RequestExporter $exporter, int $id)
    {
        $validated = $request->validate([
            'format' => ['nullable', 'string', Rule::in(RequestExporter::FORMATS)],
        ]);

        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);
        $format = $validated['format'] ?? 'curl';

        return response()->json([
            'format' => $format,
            'snippet' => $exporter->export([
                'method' => $saved->method,
                'url' => $saved->url,
                'headers' => $saved->headers ?? [],
                'body' => $saved->body,
            ], $format),
        ]);
    }

    /**
     * Render an unsaved request straight from the tester.
     */
    public function exportDraft(Request $request, RequestExporter $exporter)
    {
        $validated = $request->validate([
            'format' => ['nullable', 'string', Rule::in(RequestExporter::FORMATS)],
            'method' => 'nullable|string|max:10',
            'url' => 'required|string|max:2048',
            'headers' => 'nullable|array',
            'body' => 'nullable|string',
        ]);

        $format = $validated['format'] ?? 'curl';

        return response()->json([
            'format' => $format,
            'snippet' => $exporter->export($validated, $format),
        ]);
    }

    private function remainingQuota($user): int
    {
        if ($user->isAdmin()) {
            return OpenApiImporter::MAX_OPERATIONS;
        }

        return max(0, SavedRequestController::FREE_PLAN_LIMIT - $user->savedRequests()->count());
    }

    /**
     * Saved-request names are not unique in the schema, but colliding names in
     * the sidebar are useless, so disambiguate on import.
     */
    private function uniqueName($user, string $name): string
    {
        $taken = $user->savedRequests()->pluck('name')->all();

        return $this->disambiguate($name, $taken, 255);
    }

    private function uniqueCollectionName($user, string $name): string
    {
        return $this->disambiguate($name, $user->collections()->pluck('name')->all(), 80);
    }

    private function uniqueEnvironmentName($user, string $name): string
    {
        return $this->disambiguate($name, $user->environments()->pluck('name')->all(), 60);
    }

    private function disambiguate(string $name, array $taken, int $max): string
    {
        $name = mb_substr(trim($name) ?: 'Imported', 0, $max);
        $candidate = $name;
        $suffix = 2;

        while (in_array($candidate, $taken, true)) {
            $tail = ' '.$suffix++;
            $candidate = mb_substr($name, 0, $max - mb_strlen($tail)).$tail;
        }

        return $candidate;
    }
}
