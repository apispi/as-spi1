<?php

namespace App\Http\Controllers;

use App\Services\Variables\DynamicVariables;

/**
 * Lists the computed `{{$…}}` variables and a live example of each, for the
 * in-app reference panel.
 */
class DynamicVariableController extends Controller
{
    public function index(DynamicVariables $dynamic)
    {
        return response()->json(['variables' => $dynamic->catalogue()]);
    }
}
