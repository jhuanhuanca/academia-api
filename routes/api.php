<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\KnowledgeItemController;
use App\Http\Controllers\Api\LunaController;
use App\Http\Controllers\Api\MediaAssetController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\Webhooks\EvolutionWebhookController;
use App\Http\Controllers\Api\WhatsappInstanceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'marketluna-api',
        'app' => config('app.name'),
    ]);
});

// Webhook público (Evolution → MarketLuna). Debe responder rápido.
Route::post('/webhooks/evolution', EvolutionWebhookController::class);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::get('/dashboard', DashboardController::class);

    Route::apiResource('courses', CourseController::class);
    Route::post('/courses/{course}/payment-qr', [CourseController::class, 'uploadPaymentQr']);
    Route::post('/media-assets', [MediaAssetController::class, 'store']);
    Route::apiResource('knowledge-items', KnowledgeItemController::class)->parameters([
        'knowledge-items' => 'knowledgeItem',
    ]);

    Route::get('/flows', [FlowController::class, 'index']);
    Route::post('/flows', [FlowController::class, 'store']);
    Route::put('/flows/{flow}', [FlowController::class, 'update']);
    Route::get('/flows/{flow}', [FlowController::class, 'show']);
    Route::post('/flows/{flow}/publish', [FlowController::class, 'publish']);
    Route::put('/flows/{flow}/graph', [FlowController::class, 'syncGraph']);
    Route::post('/flows/{flow}/preview', [FlowController::class, 'preview']);

    Route::get('/whatsapp/health', [WhatsappInstanceController::class, 'health']);
    Route::get('/whatsapp-instances', [WhatsappInstanceController::class, 'index']);
    Route::post('/whatsapp-instances', [WhatsappInstanceController::class, 'store']);
    Route::get('/whatsapp-instances/{whatsappInstance}', [WhatsappInstanceController::class, 'show']);
    Route::post('/whatsapp-instances/{whatsappInstance}/connect', [WhatsappInstanceController::class, 'connect']);
    Route::post('/whatsapp-instances/{whatsappInstance}/connect-demo', [WhatsappInstanceController::class, 'connectDemo']);
    Route::post('/whatsapp-instances/{whatsappInstance}/simulate-inbound', [WhatsappInstanceController::class, 'simulateInbound']);
    Route::get('/whatsapp-instances/{whatsappInstance}/refresh', [WhatsappInstanceController::class, 'refresh']);
    Route::get('/whatsapp-instances/{whatsappInstance}/qr', [WhatsappInstanceController::class, 'qr']);
    Route::post('/whatsapp-instances/{whatsappInstance}/test-send', [WhatsappInstanceController::class, 'testSend']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/sales', [SalesController::class, 'index']);
    Route::post('/sales/{sale}/confirm-payment', [SalesController::class, 'confirmPayment']);
    Route::get('/media-assets/{mediaAsset}', [MediaAssetController::class, 'show']);

    Route::get('/luna/health', [LunaController::class, 'health']);
    Route::post('/luna/decide', [LunaController::class, 'decide']);
});
