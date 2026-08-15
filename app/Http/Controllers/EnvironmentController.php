<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    public function index(Request $request)
    {
        $environments = $request->user()->environments()->orderBy('name')->get();

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
        $environment = $request->user()->environments()->findOrFail($id);

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
        $request->user()->environments()->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
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
                Rule::unique('environments', 'name')
                    ->where('user_id', $request->user()->id)
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
     * At most one default per user.
     */
    private function syncDefault(Environment $environment): void
    {
        if (! $environment->is_default) {
            return;
        }

        Environment::where('user_id', $environment->user_id)
            ->where('id', '!=', $environment->id)
            ->update(['is_default' => false]);
    }
}
