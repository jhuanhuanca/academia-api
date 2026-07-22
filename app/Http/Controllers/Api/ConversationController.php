<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Services\WhatsApp\WhatsAppMessengerResolver;
use App\Support\DatePeriodFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['lead:id,name,phone_e164,wa_name', 'assignedUser:id,name,email']);

        DatePeriodFilter::apply($query, $request, 'updated_at');

        $items = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $items,
            'meta' => DatePeriodFilter::meta($request),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $conversation->tenant_id, 404);

        $conversation->load([
            'lead',
            'flow:id,name,status',
            'assignedUser:id,name,email',
            'whatsappInstance:id,name,status,integration,phone_e164',
        ]);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => [
                'conversation' => $conversation,
                'messages' => $messages,
                'bot_paused' => $conversation->status === 'handed_off'
                    || (bool) data_get($conversation->context, 'bot_paused', false),
            ],
        ]);
    }

    /**
     * Respuesta de un agente humano desde el panel LunaMarket.
     */
    public function reply(
        Request $request,
        Conversation $conversation,
        WhatsAppMessengerResolver $messengers
    ): JsonResponse {
        abort_if($request->user()->tenant_id !== $conversation->tenant_id, 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:4000'],
            'take_over' => ['nullable', 'boolean'],
        ]);

        $text = trim($data['text']);
        $takeOver = array_key_exists('take_over', $data)
            ? (bool) $data['take_over']
            : true;

        $conversation->loadMissing(['lead', 'whatsappInstance']);
        $instance = $this->resolveInstance($conversation);
        if (! $instance) {
            return response()->json([
                'message' => 'No hay una instancia de WhatsApp configurada para este chat.',
            ], 422);
        }

        $destination = $this->resolveDestination($conversation);
        if ($destination === '') {
            return response()->json([
                'message' => 'No se pudo determinar el número del cliente.',
            ], 422);
        }

        if ($takeOver || $conversation->status !== 'handed_off') {
            $this->pauseBot($conversation, (int) $request->user()->id);
        }

        try {
            $response = $messengers->for($instance)->sendText($instance, $destination, $text);
        } catch (Throwable $e) {
            Log::error('Agent reply falló', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar por WhatsApp: '.$e->getMessage(),
            ], 502);
        }

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $text,
            'payload' => array_merge(is_array($response) ? $response : [], [
                'agent_reply' => true,
                'agent_user_id' => $request->user()->id,
                'agent_name' => $request->user()->name,
            ]),
            'wa_message_id' => data_get($response, 'key.id') ?? data_get($response, 'messageId'),
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
        ]);

        $conversation->forceFill([
            'updated_at' => now(),
            'assigned_user_id' => $conversation->assigned_user_id ?: $request->user()->id,
        ])->save();

        return response()->json([
            'data' => [
                'message' => $message,
                'conversation' => $conversation->fresh([
                    'lead',
                    'assignedUser:id,name,email',
                ]),
                'bot_paused' => true,
            ],
            'message' => 'Mensaje enviado. El bot queda en pausa en este chat.',
        ]);
    }

    /**
     * Toma el chat: pausa el bot sin enviar mensaje.
     */
    public function take(Request $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $conversation->tenant_id, 404);

        $this->pauseBot($conversation, (int) $request->user()->id);

        return response()->json([
            'data' => [
                'conversation' => $conversation->fresh([
                    'lead',
                    'assignedUser:id,name,email',
                ]),
                'bot_paused' => true,
            ],
            'message' => 'Chat tomado. El bot no responderá hasta que lo devuelvas.',
        ]);
    }

    /**
     * Devuelve el chat al bot (reactiva el flujo).
     */
    public function release(Request $request, Conversation $conversation): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $conversation->tenant_id, 404);

        $conversation->forceFill([
            'status' => 'waiting_input',
            'assigned_user_id' => null,
            'context' => array_merge($conversation->context ?? [], [
                'handed_off' => false,
                'bot_paused' => false,
                'released_at' => now()->toIso8601String(),
                'released_by' => $request->user()->id,
            ]),
        ])->save();

        return response()->json([
            'data' => [
                'conversation' => $conversation->fresh([
                    'lead',
                    'assignedUser:id,name,email',
                ]),
                'bot_paused' => false,
            ],
            'message' => 'Chat devuelto al bot. El asistente puede volver a responder.',
        ]);
    }

    private function pauseBot(Conversation $conversation, int $userId): void
    {
        $conversation->forceFill([
            'status' => 'handed_off',
            'assigned_user_id' => $userId,
            'context' => array_merge($conversation->context ?? [], [
                'handed_off' => true,
                'bot_paused' => true,
                'handed_off_at' => now()->toIso8601String(),
                'assigned_by' => $userId,
            ]),
        ])->save();
    }

    private function resolveInstance(Conversation $conversation): ?WhatsappInstance
    {
        if ($conversation->whatsappInstance) {
            return $conversation->whatsappInstance;
        }

        if ($conversation->whatsapp_instance_id) {
            return WhatsappInstance::query()->find($conversation->whatsapp_instance_id);
        }

        return WhatsappInstance::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('status', 'open')
            ->latest('id')
            ->first()
            ?: WhatsappInstance::query()
                ->where('tenant_id', $conversation->tenant_id)
                ->latest('id')
                ->first();
    }

    private function resolveDestination(Conversation $conversation): string
    {
        $fromContext = data_get($conversation->context ?? [], 'reply_to');
        if (is_string($fromContext) && trim($fromContext) !== '') {
            return ltrim(trim($fromContext), '+');
        }

        $phone = (string) ($conversation->lead?->phone_e164 ?? '');

        return ltrim($phone, '+');
    }
}
