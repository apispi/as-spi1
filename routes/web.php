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
use App\Http\Controllers\GrpcTestController;
use App\Http\Controllers\MqttTestController;
use App\Http\Controllers\AmqpTestController;
use App\Http\Controllers\RequestHistoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CatalogItemController;
use App\Http\Controllers\ConnectorSyncController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\McpSecurityController;
use App\Http\Controllers\McpConformanceController;
use App\Http\Controllers\AgentLoopController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\EnvironmentController;

// Google OAuth (full-page redirect flow). Registered before the SPA
// catch-all, which also excludes the auth/ prefix.
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
    ->middleware('throttle:auth-attempts');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:auth-attempts');

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api\/|auth\/).*$');

Route::post('/api/proxy', [ProxyController::class, 'handle'])->middleware(['throttle:proxy', 'resolve.vars']);

// Public, read-only view of a shared inspection report (token-gated, no auth).
Route::get('/api/reports/shared/{token}', [ReportController::class, 'showShared'])
    ->middleware('throttle:proxy');

Route::post('/api/register', [AuthController::class, 'register'])->middleware('throttle:auth-attempts');
Route::post('/api/register/start', [RegistrationController::class, 'start'])->middleware('throttle:auth-attempts');
Route::post('/api/register/complete', [RegistrationController::class, 'complete'])->middleware('throttle:auth-attempts');
Route::post('/api/login', [AuthController::class, 'login'])->middleware('throttle:auth-attempts');
Route::post('/api/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/api/user', [AuthController::class, 'user'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/api/saved-requests', [SavedRequestController::class, 'index']);
    Route::post('/api/saved-requests', [SavedRequestController::class, 'store']);
    Route::delete('/api/saved-requests/{id}', [SavedRequestController::class, 'destroy']);
    Route::get('/api/tools/active', [ToolController::class, 'active']);
    Route::get('/api/prompts/active', [PromptController::class, 'active']);
    Route::get('/api/resources/active', [ResourceController::class, 'active']);
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
    Route::get('/api/environments', [EnvironmentController::class, 'index']);
    Route::post('/api/environments', [EnvironmentController::class, 'store']);
    Route::put('/api/environments/{id}', [EnvironmentController::class, 'update']);
    Route::delete('/api/environments/{id}', [EnvironmentController::class, 'destroy']);

    Route::middleware(['throttle:outbound-test', 'resolve.vars'])->group(function () {
        Route::post('/api/mcp/test', [McpTestController::class, 'test']);
        Route::post('/api/a2a/test', [A2aTestController::class, 'test']);
        Route::post('/api/grpc/test', [GrpcTestController::class, 'test']);
        Route::post('/api/mqtt/test', [MqttTestController::class, 'test']);
        Route::post('/api/amqp/test', [AmqpTestController::class, 'test']);
    });

    // AI-assisted request authoring (#4). Powered by the caller's SCX key.
    Route::post('/api/ai/author', [AiAssistController::class, 'author'])->middleware('throttle:outbound-test');
    Route::post('/api/ai/explain', [AiAssistController::class, 'explain'])->middleware('throttle:outbound-test');
    Route::post('/api/ai/assert', [AiAssistController::class, 'assert'])->middleware('throttle:outbound-test');
    Route::post('/api/ai/fix', [AiAssistController::class, 'fix'])->middleware('throttle:outbound-test');

    // MCP security scanner (#3) on caller-supplied tool/prompt descriptors.
    Route::post('/api/mcp/security/scan', [McpSecurityController::class, 'scan'])->middleware('throttle:outbound-test');

    // Saved inspection reports. `compare` is declared before the {report}
    // wildcard so it isn't captured as a report id.
    Route::get('/api/reports', [ReportController::class, 'index']);
    Route::get('/api/reports/compare', [ReportController::class, 'compare']);
    Route::get('/api/reports/{report}', [ReportController::class, 'show']);
    Route::delete('/api/reports/{report}', [ReportController::class, 'destroy']);
    Route::post('/api/reports/{report}/share', [ReportController::class, 'share']);
    Route::delete('/api/reports/{report}/share', [ReportController::class, 'revokeShare']);
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
    Route::post('/api/admin/catalog/{catalogItem}/check', [ConnectorSyncController::class, 'check']);
    // Connector deep-inspection: conformance grade (#2), live security scan
    // (#3), and an agent-in-the-loop run (#1).
    Route::post('/api/admin/catalog/{catalogItem}/conformance', [McpConformanceController::class, 'grade']);
    Route::post('/api/admin/catalog/{catalogItem}/security-scan', [McpSecurityController::class, 'scanConnector']);
    Route::post('/api/admin/catalog/{catalogItem}/agent-loop', [AgentLoopController::class, 'run']);
    Route::post('/api/admin/users/{id}/toggle-admin', [AdminController::class, 'toggleAdmin']);
    Route::delete('/api/admin/users/{id}', [AdminController::class, 'deleteUser']);
});