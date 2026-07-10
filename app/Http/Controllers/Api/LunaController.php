<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\LunaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LunaController extends Controller
{
    public function health(LunaClient $luna): JsonResponse
    {
        try {
            return response()->json(['data' => $luna->health()]);
        } catch (Throwable $e) {
            return response()->json([
                'data' => ['status' => 'down', 'error' => $e->getMessage()],
            ], 503);
        }
    }

    public function decide(Request $request, LunaClient $luna): JsonResponse
    {
        $payload = $request->validate([
            'user_message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable'],
            'allowed_knowledge' => ['nullable', 'array'],
            'available_transitions' => ['nullable', 'array'],
            'current_node' => ['nullable', 'array'],
            'lead_context' => ['nullable', 'array'],
            'system_hint' => ['nullable', 'string'],
            'min_confidence' => ['nullable', 'numeric'],
        ]);

        $leadContext = $payload['lead_context'] ?? new \stdClass();
        if (is_array($leadContext) && $leadContext === []) {
            $leadContext = new \stdClass();
        }

        $currentNode = $payload['current_node'] ?? ['type' => 'ai_reply', 'config' => new \stdClass()];
        if (isset($currentNode['config']) && is_array($currentNode['config']) && $currentNode['config'] === []) {
            $currentNode['config'] = new \stdClass();
        }

        $decision = $luna->decide([
            'tenant_id' => $request->user()->tenant_id,
            'conversation_id' => $payload['conversation_id'] ?? 'manual',
            'user_message' => $payload['user_message'],
            'allowed_knowledge' => $payload['allowed_knowledge'] ?? [],
            'available_transitions' => $payload['available_transitions'] ?? ['default', 'human'],
            'current_node' => $currentNode,
            'lead_context' => $leadContext,
            'system_hint' => $payload['system_hint'] ?? null,
            'min_confidence' => $payload['min_confidence'] ?? null,
        ]);

        return response()->json(['data' => $decision]);
    }
}
