<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SavedRequestController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\UserPreferencesController;
use App\Http\Controllers\ScxChatController;
use App\Http\Controllers\McpTestController;
use App\Http\Controllers\A2aTestController;

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api\/).*$');

Route::post('/api/proxy', [ProxyController::class, 'handle'])->middleware('throttle:proxy');

Route::post('/api/register', [AuthController::class, 'register'])->middleware('throttle:auth-attempts');
Route::post('/api/login', [AuthController::class, 'login'])->middleware('throttle:auth-attempts');
Route::post('/api/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/api/user', [AuthController::class, 'user'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/api/saved-requests', [SavedRequestController::class, 'index']);
    Route::post('/api/saved-requests', [SavedRequestController::class, 'store']);
    Route::delete('/api/saved-requests/{id}', [SavedRequestController::class, 'destroy']);
    Route::put('/api/user/scx-api-key', [UserPreferencesController::class, 'updateScxApiKey']);
    Route::get('/api/user/scx-api-key', [UserPreferencesController::class, 'getScxApiKey']);
    Route::put('/api/user/scx-model', [UserPreferencesController::class, 'updateScxModel']);
    Route::post('/api/scx/chat', [ScxChatController::class, 'chat']);
    Route::post('/api/mcp/test', [McpTestController::class, 'test'])->middleware('throttle:outbound-test');
    Route::post('/api/a2a/test', [A2aTestController::class, 'test'])->middleware('throttle:outbound-test');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/api/admin/users', [AdminController::class, 'users']);
    Route::get('/api/admin/stats', [AdminController::class, 'stats']);
    Route::post('/api/admin/users/{id}/toggle-admin', [AdminController::class, 'toggleAdmin']);
    Route::delete('/api/admin/users/{id}', [AdminController::class, 'deleteUser']);
});