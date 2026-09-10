<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    public function index(Request $request)
    {
        $environments = Environment::inWorkspaceOf($request->user())
            ->with('owner:id,name')->orderBy('name')->get();

        return response()->json($environments->map->toClientArray()->values());
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->environments()->count() >= Environment::MAX_PER_USER) {
            return response()->json([
                'message' => 'Environment limit reached ('.Environment::MAX_PER_USER.'). Delete one to add another.',
            ], 422);
        }

        $validated = $this->validated($request, null);

        $environment = $user->environments()->create([
            'name' => $validated['name'],
            'variables' => $validated['variables'],
            'is_default' => $validated['is_default'],
        ]);

        $this->syncDefault($environment);

        return response()->json($environment->fresh()->toClientArray(), 201);
    }

    public function update(Request $request, int $id)
    {
        $environment = Environment::inWorkspaceOf($request->user())->findOrFail($id);

        $validated = $this->validated($request, $environment);

        $environment->update([
            'name' => $validated['name'],
            'variables' => $validated['variables'],
            'is_default' => $validated['is_default'],
        ]);

        $this->syncDefault($environment);

        return response()->json($environment->fresh()->toClientArray());
    }

    public function destroy(Request $request, int $id)
    {
        Environment::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Download an environment as JSON to share or back up. Secret VALUES are
     * never exported — a secret variable exports its name and secret flag only,
     * so the importer re-enters the credential.
     */
    public function export(Request $request, int $id)
    {
        $environment = Environment::inWorkspaceOf($request->user())->findOrFail($id);

        $variables = collect($environment->variables ?? [])
            ->filter(fn ($v) => is_array($v) && ($v['key'] ?? '') !== '')
            ->map(fn ($v) => [
                'key' => (string) $v['key'],
                'value' => ! empty($v['secret']) ? '' : (string) ($v['value'] ?? ''),
                'secret' => ! empty($v['secret']),
            ])->values()->all();

        $filename = Str::slug($environment->name ?: 'environment').'.spi-env.json';

        return response()->json([
            'spi_environment' => '1.0',
            'name' => $environment->name,
            'variables' => $variables,
        ])->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    /**
     * Create a new environment from an exported document. Names are unique per
     * workspace, so a clash gets a numeric suffix rather than an error.
     */
    public function import(Request $request)
    {
        $user = $request->user();

        if ($user->environments()->count() >= Environment::MAX_PER_USER) {
            return response()->json([
                'message' => 'Environment limit reached ('.Environment::MAX_PER_USER.'). Delete one to add another.',
            ], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:60',
            'variables' => 'nullable|array|max:'.Environment::MAX_VARIABLES,
            'variables.*.key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'variables.*.value' => 'nullable|string|max:4096',
            'variables.*.secret' => 'nullable|boolean',
        ], [
            'variables.*.key.regex' => 'Variable names may use letters, numbers, dot, dash, and underscore only.',
        ]);

        $seen = [];
        $variables = collect($data['variables'] ?? [])->map(function ($row) use (&$seen) {
            $key = $row['key'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['variables' => "Duplicate variable name: {$key}"]);
            }
            $seen[$key] = true;

            return ['key' => $key, 'value' => (string) ($row['value'] ?? ''), 'secret' => ! empty($row['secret'])];
        })->values()->all();

        $environment = $user->environments()->create([
            'name' => $this->uniqueName($user, $data['name']),
            'variables' => $variables,
            'is_default' => false,
        ]);

        return response()->json($environment->fresh()->toClientArray(), 201);
    }

    /** A workspace-unique name, suffixing "(2)", "(3)", … on collision. */
    private function uniqueName($user, string $base): string
    {
        $base = trim($base) ?: 'Imported environment';
        $name = $base;
        $i = 2;
        while (Environment::inWorkspaceOf($user)->where('name', $name)->exists()) {
            $name = $base.' ('.$i++.')';
        }

        return $name;
    }

    /**
     * Validate the payload and normalise the variable rows.
     *
     * Secret values are never sent to the client, so an unchanged secret comes
     * back with an empty value. Carry the stored value forward in that case,
     * otherwise every save would wipe the credential.
     */
    private function validated(Request $request, ?Environment $existing): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                // Environments are selected by name across the shared
                // workspace, so names are unique per workspace, not per user.
                Rule::unique('environments', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            'is_default' => 'nullable|boolean',
            'variables' => 'nullable|array|max:'.Environment::MAX_VARIABLES,
            'variables.*.key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'variables.*.value' => 'nullable|string|max:4096',
            'variables.*.secret' => 'nullable|boolean',
        ], [
            'variables.*.key.regex' => 'Variable names may use letters, numbers, dot, dash, and underscore only.',
        ]);

        $previous = collect($existing?->variables ?? [])->keyBy('key');
        $seen = [];

        $variables = collect($data['variables'] ?? [])->map(function ($row) use ($previous, &$seen) {
            $key = $row['key'];
            $secret = ! empty($row['secret']);
            $value = (string) ($row['value'] ?? '');

            if ($secret && $value === '') {
                $value = (string) ($previous[$key]['value'] ?? '');
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'variables' => "Duplicate variable name: {$key}",
                ]);
            }
            $seen[$key] = true;

            return ['key' => $key, 'value' => $value, 'secret' => $secret];
        })->values()->all();

        return [
            'name' => $data['name'],
            'variables' => $variables,
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }

    /**
     * At most one default per workspace: the default is shared, so setting one
     * clears any other in the same organisation.
     */
    private function syncDefault(Environment $environment): void
    {
        if (! $environment->is_default) {
            return;
        }

        Environment::inWorkspaceOf($environment->owner)
            ->where('id', '!=', $environment->id)
            ->update(['is_default' => false]);
    }
}
