<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CatalogItemController extends Controller
{
    /**
     * List catalog items, optionally filtered by type and active state.
     * The Catalog section passes a type; the Active section also passes
     * active=1.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(CatalogItem::TYPES)],
            'active' => 'nullable|boolean',
        ]);

        $query = CatalogItem::query()->orderBy('name');

        if (! empty($validated['type'])) {
            $query->ofType($validated['type']);
        }

        if ($request->boolean('active')) {
            $query->active();
        }

        return response()->json($query->get());
    }

    /**
     * Counts per type, so the tabs can show badges without fetching rows.
     */
    public function counts(Request $request)
    {
        $onlyActive = $request->boolean('active');

        $counts = CatalogItem::query()
            ->when($onlyActive, fn ($q) => $q->active())
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return response()->json(
            collect(CatalogItem::TYPES)
                ->mapWithKeys(fn ($type) => [$type => (int) ($counts[$type] ?? 0)])
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(CatalogItem::TYPES)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'version' => 'nullable|string|max:50',
            'provider' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['slug'] = $this->uniqueSlug($validated['type'], $validated['name']);

        return response()->json(CatalogItem::create($validated), 201);
    }

    public function update(Request $request, CatalogItem $catalogItem)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'version' => 'nullable|string|max:50',
            'provider' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $catalogItem->update($validated);

        return response()->json($catalogItem);
    }

    public function destroy(CatalogItem $catalogItem)
    {
        $catalogItem->delete();

        return response()->json(['message' => 'Item deleted.']);
    }

    /**
     * Move an item between Catalog and Active.
     */
    public function toggleActive(CatalogItem $catalogItem)
    {
        $catalogItem->is_active = ! $catalogItem->is_active;
        $catalogItem->save();

        return response()->json([
            'message' => $catalogItem->is_active ? 'Item activated.' : 'Item deactivated.',
            'item' => $catalogItem,
        ]);
    }

    protected function uniqueSlug(string $type, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;

        while (CatalogItem::ofType($type)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
