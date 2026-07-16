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
use App\Http\Controllers\RequestHistoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CatalogItemController;
use App\Http\Controllers\ConnectorSyncController;
use App\Http\Controllers\ToolController;

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
    Route::get('/api/tools/active', [ToolController::class, 'active']);
    Route::get('/api/history', [RequestHistoryController::class, 'index']);
    Route::delete('/api/history', [RequestHistoryController::class, 'clear']);
    Route::put('/api/user/scx-api-key', [UserPreferencesController::class, 'updateScxApiKey']);
    Route::get('/api/user/scx-api-key', [UserPreferencesController::class, 'getScxApiKey']);
    Route::put('/api/user/scx-model', [UserPreferencesController::class, 'updateScxModel']);
    Route::post('/api/scx/chat', [ScxChatController::class, 'chat']);
    Route::put('/api/user/password', [AuthController::class, 'changePassword']);
    Route::put('/api/user/profile', [UserController::class, 'updateProfile']);
    Route::get('/api/user/stats', [UserController::class, 'stats']);
    Route::get('/api/user/activity', [UserController::class, 'activity']);
    Route::get('/api/user/api-key', [UserController::class, 'apiKey']);
    Route::post('/api/user/api-key/regenerate', [UserController::class, 'regenerateApiKey']);
    Route::get('/api/user/preferences', [UserController::class, 'preferences']);
    Route::put('/api/user/preferences', [UserController::class, 'updatePreferences']);
    Route::delete('/api/user/account', [UserController::class, 'deleteAccount']);
    Route::post('/api/mcp/test', [McpTestController::class, 'test'])->middleware('throttle:outbound-test');
    Route::post('/api/a2a/test', [A2aTestController::class, 'test'])->middleware('throttle:outbound-test');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/api/admin/users', [AdminController::class, 'users']);
    Route::get('/api/admin/stats', [AdminController::class, 'stats']);
    Route::get('/api/admin/actions', [AdminController::class, 'actions']);
    Route::get('/api/admin/catalog', [CatalogItemController::class, 'index']);
    Route::get('/api/admin/catalog/counts', [CatalogItemController::class, 'counts']);
    Route::post('/api/admin/catalog', [CatalogItemController::class, 'store']);
    Route::put('/api/admin/catalog/{catalogItem}', [CatalogItemController::class, 'update']);
    Route::delete('/api/admin/catalog/{catalogItem}', [CatalogItemController::class, 'destroy']);
    Route::post('/api/admin/catalog/{catalogItem}/toggle-active', [CatalogItemController::class, 'toggleActive']);
    Route::post('/api/admin/catalog/{catalogItem}/sync', [ConnectorSyncController::class, 'sync']);
    Route::post('/api/admin/users/{id}/toggle-admin', [AdminController::class, 'toggleAdmin']);
    Route::delete('/api/admin/users/{id}', [AdminController::class, 'deleteUser']);
});