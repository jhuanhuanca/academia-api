<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Conversation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['lead:id,name,phone_e164,wa_name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $conversation->tenant_id, 404);

        $conversation->load(['lead', 'flow:id,name,status']);
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => [
                'conversation' => $conversation,
                'messages' => $messages,
            ],
        ]);
    }
}
