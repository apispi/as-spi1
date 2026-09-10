<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only management of customer organisations.
 */
class OrganisationController extends Controller
{
    public function index()
    {
        $organisations = Organisation::withCount('users')->orderBy('name')->get();

        return response()->json([
            'organisations' => $organisations,
            // Users with no organisation, so an admin can see who is unassigned.
            'unassigned_users' => \App\Models\User::whereNull('organisation_id')->count(),
        ]);
    }

    /**
     * The members of one organisation, for the admin member-management panel.
     */
    public function members(Organisation $organisation)
    {
        $members = $organisation->users()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_admin'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => (bool) $u->is_admin,
            ]);

        return response()->json(['members' => $members]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, null);

        $organisation = Organisation::create($validated + [
            'slug' => Organisation::uniqueSlug($validated['name']),
        ]);

        return response()->json($organisation->loadCount('users'), 201);
    }

    public function update(Request $request, Organisation $organisation)
    {
        $validated = $this->validated($request, $organisation);

        // Keep the slug in step with a rename, without breaking uniqueness.
        if ($validated['name'] !== $organisation->name) {
            $validated['slug'] = Organisation::uniqueSlug($validated['name'], $organisation->id);
        }

        $organisation->update($validated);

        return response()->json($organisation->fresh()->loadCount('users'));
    }

    /**
     * Deleting an organisation unassigns its members rather than removing
     * them — the accounts belong to people, not to the grouping.
     */
    public function destroy(Organisation $organisation)
    {
        $organisation->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validated(Request $request, ?Organisation $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('organisations', 'name')->ignore($existing?->id),
            ],
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
