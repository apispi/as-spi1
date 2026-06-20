<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SavedRequest;

class SavedRequestController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->savedRequests()->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'method' => 'required|string',
            'url' => 'required|url',
            'headers' => 'nullable|array',
            'body' => 'nullable|string',
        ]);

        $savedRequest = $request->user()->savedRequests()->create($validated);

        return response()->json($savedRequest, 201);
    }

    public function destroy(Request $request, $id)
    {
        $savedRequest = $request->user()->savedRequests()->findOrFail($id);
        $savedRequest->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
