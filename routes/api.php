<?php

use App\Http\Controllers\A2aTestController;
use App\Http\Controllers\McpTestController;
use App\Http\Controllers\ProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Programmatic API (v1)
|--------------------------------------------------------------------------
|
| Stateless routes authenticated with a personal API key (Bearer token), for
| driving the testers from scripts. These deliberately live apart from the
| session-authenticated /api/* routes the SPA uses: no cookies means no CSRF
| concern, and no CSRF exemption is needed on the SPA's routes.
|
| Prefixed with api/v1 — see bootstrap/app.php.
|
*/

Route::middleware(['auth.apitoken', 'throttle:outbound-test'])->group(function () {
    Route::post('/proxy', [ProxyController::class, 'handle']);
    Route::post('/mcp/test', [McpTestController::class, 'test']);
    Route::post('/a2a/test', [A2aTestController::class, 'test']);
});
