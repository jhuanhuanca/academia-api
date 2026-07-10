<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Flow;
use App\Models\KnowledgeItem;
use App\Models\Sale;
use App\Models\WhatsappInstance;
use App\Services\AI\LunaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LunaClient $luna): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $lunaHealth = null;
        try {
            $lunaHealth = $luna->health();
        } catch (Throwable) {
            $lunaHealth = ['status' => 'down'];
        }

        return response()->json([
            'kpis' => [
                'conversations' => Conversation::query()->where('tenant_id', $tenantId)->count(),
                'open_conversations' => Conversation::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', ['open', 'waiting_input', 'waiting_payment'])
                    ->count(),
                'pending_payments' => Sale::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'pending_payment')
                    ->count(),
                'paid_sales' => Sale::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', ['paid', 'delivered'])
                    ->count(),
                'delivered' => Sale::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'delivered')
                    ->count(),
            ],
            'checklist' => [
                'course' => Course::query()->where('tenant_id', $tenantId)->where('is_active', true)->exists(),
                'knowledge' => KnowledgeItem::query()->where('tenant_id', $tenantId)->where('is_active', true)->exists(),
                'whatsapp' => WhatsappInstance::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'open')
                    ->exists(),
                'flow' => Flow::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'published')
                    ->exists(),
            ],
            'luna' => $lunaHealth,
        ]);
    }
}
