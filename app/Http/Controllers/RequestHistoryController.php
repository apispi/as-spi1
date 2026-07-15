<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RequestHistoryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->requestHistories()->orderByDesc('id')->limit(50)->get()
        );
    }

    public function clear(Request $request)
    {
        $request->user()->requestHistories()->delete();

        return response()->json(['message' => 'History cleared.']);
    }
}
