<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\Course;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\KnowledgeItem;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\WhatsappInstance;
use App\Services\AI\LunaClient;
use App\Services\Flow\FlowRunner;
use App\Services\Payments\PaymentConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ConversationOrchestrator
{
    public function __construct(
        private readonly EvolutionClient $evolution,
        private readonly FlowRunner $flowRunner,
        private readonly LunaClient $luna,
    ) {
    }

    /**
     * Procesa un mensaje entrante ya normalizado.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function handleIncoming(WhatsappInstance $instance, array $incoming): array
    {
        if (! empty($incoming['from_me'])) {
            return ['skipped' => true, 'reason' => 'from_me'];
        }

        $phone = $incoming['phone_e164'] ?? null;
        if (! $phone) {
            return ['skipped' => true, 'reason' => 'no_phone'];
        }

        return DB::transaction(function () use ($instance, $incoming, $phone) {
            $lead = Lead::query()->firstOrCreate(
                [
                    'tenant_id' => $instance->tenant_id,
                    'phone_e164' => $phone,
                ],
                [
                    'wa_name' => $incoming['wa_name'] ?? null,
                    'opt_in_at' => now(),
                    'last_message_at' => now(),
                ]
            );

            $lead->forceFill([
                'wa_name' => $incoming['wa_name'] ?? $lead->wa_name,
                'last_message_at' => now(),
            ])->save();

            $conversation = $this->resolveConversation($instance, $lead);
            if (! $conversation) {
                $this->evolution->sendText(
                    $instance->evolution_instance,
                    $phone,
                    'Hola, aún no hay un flujo publicado en MarketLuna. Configura uno en el dashboard.'
                );

                return ['skipped' => true, 'reason' => 'no_flow'];
            }

            if (! empty($incoming['wa_message_id'])) {
                $exists = Message::query()
                    ->where('tenant_id', $instance->tenant_id)
                    ->where('wa_message_id', $incoming['wa_message_id'])
                    ->exists();
                if ($exists) {
                    return ['skipped' => true, 'reason' => 'duplicate'];
                }
            }

            Message::create([
                'tenant_id' => $instance->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => $incoming['type'] ?? 'text',
                'body' => $incoming['body'] ?? null,
                'payload' => $incoming,
                'wa_message_id' => $incoming['wa_message_id'] ?? null,
                'created_at' => now(),
            ]);

            $flow = Flow::query()->findOrFail($conversation->flow_id);
            $current = $this->resolveFlowNode($flow, $conversation->current_node_id)
                ?? $this->resolveStartNode($flow);

            // Conversación trabada (pago/handoff) + saludo → reiniciar flujo
            if (
                $current
                && in_array($conversation->status, ['waiting_payment', 'handed_off'], true)
                && $this->looksLikeRestart((string) ($incoming['body'] ?? ''))
            ) {
                $conversation->forceFill([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'current_node_id' => null,
                ])->save();

                $conversation = $this->resolveConversation($instance, $lead);
                if (! $conversation) {
                    return ['skipped' => true, 'reason' => 'no_flow_after_restart'];
                }

                return $this->runFromStart($instance, $conversation, $flow, $lead, $phone);
            }

            // Primera entrada o nodo start inválido/borrado → cadena desde inicio
            if (! $current || $current->type === 'start') {
                return $this->runFromStart($instance, $conversation, $flow, $lead, $phone);
            }

            $triggerType = 'default';
            $triggerKey = '';

            if (($incoming['type'] ?? '') === 'button_reply' && ! empty($incoming['button_id'])) {
                $triggerType = 'button';
                $triggerKey = (string) $incoming['button_id'];
            } elseif (($incoming['type'] ?? '') === 'list_reply' && ! empty($incoming['list_id'])) {
                $triggerType = 'list';
                $triggerKey = (string) $incoming['list_id'];
            } elseif ($current && $current->type === 'ai_reply') {
                return $this->handleAiNode($instance, $conversation, $flow, $current, $lead, $phone, (string) ($incoming['body'] ?? ''));
            } elseif ($current && in_array($current->type, ['buttons', 'list'], true) && ! empty($incoming['body'])) {
                // Texto libre sobre menú → intentar match por label o pasar a ai si existe edge
                $matched = $this->matchButtonByLabel($current, (string) $incoming['body']);
                if ($matched) {
                    $triggerType = 'button';
                    $triggerKey = $matched;
                } else {
                    $this->sendOutbound(
                        $instance,
                        $conversation,
                        $phone,
                        'Por favor elige una de las opciones del menú 🙂',
                        'text',
                        $current->id
                    );

                    return ['ok' => true, 'action' => 'reprompt_buttons'];
                }
            } elseif ($current && $current->type === 'wait_payment') {
                return app(PaymentConfirmationService::class)->handleReceiptSubmission(
                    $instance,
                    $conversation,
                    $current,
                    $phone,
                    $incoming
                );
            } else {
                // Nodo message u otros: avanzar default
                $triggerType = 'default';
                $triggerKey = '';
            }

            $advanced = $this->flowRunner->advance($flow, $current, $triggerType, $triggerKey);
            $next = $advanced['node'];

            if (! $next) {
                $this->sendOutbound(
                    $instance,
                    $conversation,
                    $phone,
                    'Recibido. Si necesitas ayuda, escribe "humano".',
                    'text',
                    $current?->id
                );

                return ['ok' => true, 'action' => 'no_edge'];
            }

            return $this->executeNodeChain($instance, $conversation, $flow, $lead, $phone, $next);
        });
    }

    /**
     * Confirma pago manual y entrega el curso por WhatsApp.
     *
     * @return array<string, mixed>
     */
    public function deliverAfterPaymentConfirmation(Sale $sale): array
    {
        $conversation = $sale->conversation;
        $instance = $conversation?->whatsappInstance;
        $lead = $sale->lead;
        $phone = $lead?->phone_e164;

        if (! $conversation || ! $instance || ! $phone) {
            return ['ok' => false, 'reason' => 'missing_conversation_context'];
        }

        $flow = Flow::query()->findOrFail($conversation->flow_id);
        $current = $conversation->current_node_id
            ? FlowNode::query()->find($conversation->current_node_id)
            : null;

        $conversation->forceFill([
            'context' => array_merge($conversation->context ?? [], [
                'sale_id' => $sale->id,
                'course_id' => $sale->course_id,
            ]),
        ])->save();

        $advanced = $this->flowRunner->advance($flow, $current, 'payment_paid', '');
        $next = $advanced['node'];

        if (! $next) {
            $next = FlowNode::query()
                ->where('flow_id', $flow->id)
                ->where('type', 'deliver_course')
                ->first();
        }

        if (! $next) {
            return ['ok' => false, 'reason' => 'no_deliver_node'];
        }

        return $this->executeNodeChain($instance, $conversation, $flow, $lead, $phone, $next);
    }

    public function sendQuickReply(
        WhatsappInstance $instance,
        Conversation $conversation,
        string $phone,
        string $text,
        ?int $nodeId = null
    ): void {
        $this->sendOutbound($instance, $conversation, $phone, $text, 'text', $nodeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function runFromStart(
        WhatsappInstance $instance,
        Conversation $conversation,
        Flow $flow,
        Lead $lead,
        string $phone
    ): array {
        $start = $this->resolveStartNode($flow);

        if (! $start) {
            Log::warning('Flow sin nodo start', ['flow_id' => $flow->id]);

            return ['skipped' => true, 'reason' => 'no_start_node'];
        }

        if ($flow->start_node_id !== $start->id) {
            $flow->forceFill(['start_node_id' => $start->id])->save();
        }

        $advanced = $this->flowRunner->advance($flow, $start, 'default', '');
        $next = $advanced['node'] ?? null;

        // Si no hay edge desde start (flujo mal guardado), buscar primer nodo útil
        if (! $next || $next->id === $start->id || $next->type === 'start') {
            $next = FlowNode::query()
                ->where('flow_id', $flow->id)
                ->whereIn('type', ['message', 'buttons', 'ai_reply', 'list'])
                ->orderBy('id')
                ->first();
        }

        if (! $next) {
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                'Hola, aún no hay un flujo completo publicado. Revisa Flow Builder (Guardar + Publicar).',
                'text',
                $start->id
            );

            return ['skipped' => true, 'reason' => 'no_next_after_start'];
        }

        return $this->executeNodeChain($instance, $conversation, $flow, $lead, $phone, $next);
    }

    private function resolveStartNode(Flow $flow): ?FlowNode
    {
        if ($flow->start_node_id) {
            $byId = FlowNode::query()
                ->where('flow_id', $flow->id)
                ->where('id', $flow->start_node_id)
                ->first();
            if ($byId) {
                return $byId;
            }
        }

        return FlowNode::query()
            ->where('flow_id', $flow->id)
            ->where('type', 'start')
            ->orderBy('id')
            ->first();
    }

    private function resolveFlowNode(Flow $flow, ?int $nodeId): ?FlowNode
    {
        if (! $nodeId) {
            return null;
        }

        return FlowNode::query()
            ->where('flow_id', $flow->id)
            ->where('id', $nodeId)
            ->first();
    }

    private function looksLikeRestart(string $body): bool
    {
        $text = mb_strtolower(trim($body));
        if ($text === '') {
            return false;
        }

        $keys = ['hola', 'buenas', 'buen día', 'buen dia', 'menu', 'menú', 'inicio', 'reiniciar', 'empezar'];

        foreach ($keys as $key) {
            if ($text === $key || str_starts_with($text, $key.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ejecuta nodos en cadena hasta encontrar uno que espere input.
     *
     * @return array<string, mixed>
     */
    private function executeNodeChain(
        WhatsappInstance $instance,
        Conversation $conversation,
        Flow $flow,
        Lead $lead,
        string $phone,
        FlowNode $node
    ): array {
        $guard = 0;
        $executed = [];

        while ($node && $guard < 8) {
            $guard++;
            $executed[] = $node->node_key;
            $conversation->forceFill([
                'current_node_id' => $node->id,
                'status' => $this->statusForNode($node),
            ])->save();

            if (in_array($node->type, ['buttons', 'list', 'ai_reply', 'wait_payment', 'handoff', 'collect_data'], true)) {
                $this->renderAndSendNode($instance, $conversation, $lead, $phone, $node);

                return ['ok' => true, 'waiting_on' => $node->type, 'path' => $executed];
            }

            $this->renderAndSendNode($instance, $conversation, $lead, $phone, $node);

            if (in_array($node->type, ['deliver_course', 'handoff'], true)) {
                return ['ok' => true, 'finished' => $node->type, 'path' => $executed];
            }

            $advanced = $this->flowRunner->advance($flow, $node, 'default', '');
            if (! $advanced['node'] || $advanced['node']->id === $node->id) {
                break;
            }
            $node = $advanced['node'];
        }

        return ['ok' => true, 'path' => $executed];
    }

    private function renderAndSendNode(
        WhatsappInstance $instance,
        Conversation $conversation,
        Lead $lead,
        string $phone,
        FlowNode $node
    ): void {
        $config = $node->config ?? [];

        match ($node->type) {
            'message' => $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                (string) ($config['text'] ?? $node->name),
                'text',
                $node->id
            ),
            'buttons' => $this->sendButtonsNode($instance, $conversation, $phone, $node, $config),
            'ai_reply' => null,
            'send_payment_qr' => $this->sendPaymentQr($instance, $conversation, $lead, $phone, $node, $config),
            'wait_payment' => $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                'Cuando completes el pago, envíame la *foto del comprobante* por aquí 👀',
                'text',
                $node->id
            ),
            'deliver_course' => $this->deliverCourse($instance, $conversation, $lead, $phone, $node, $config),
            'handoff' => $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                (string) ($config['text'] ?? 'Te derivo con una persona del equipo. En breve te responden.'),
                'text',
                $node->id
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sendButtonsNode(
        WhatsappInstance $instance,
        Conversation $conversation,
        string $phone,
        FlowNode $node,
        array $config
    ): void {
        $text = (string) ($config['text'] ?? 'Elige una opción');
        $buttons = $config['buttons'] ?? [];
        $footer = $config['footer'] ?? 'MarketLuna';
        $title = (string) ($config['title'] ?? 'Opciones');

        // Primera línea como título corto; el resto como descripción (estilo WhatsApp)
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
        $titleLine = trim((string) ($lines[0] ?? $title));
        $description = count($lines) > 1
            ? trim(implode("\n", array_slice($lines, 1)))
            : $text;

        try {
            $response = $this->evolution->sendButtons(
                $instance->evolution_instance,
                $phone,
                $titleLine !== '' ? $titleLine : $title,
                $description !== '' ? $description : $text,
                is_array($buttons) ? $buttons : [],
                is_string($footer) ? $footer : 'MarketLuna'
            );
            $this->storeOutbound($instance, $conversation, $node->id, 'buttons', $text, $response);
        } catch (Throwable $e) {
            // Baileys a menudo no soporta botones nativos → menú numerado (sí funciona)
            Log::warning('sendButtons failed, fallback menú numerado', ['error' => $e->getMessage()]);
            $out = [rtrim($text), '', 'Responde con el número o el nombre:'];
            $i = 1;
            foreach ($buttons as $b) {
                $label = (string) ($b['label'] ?? $b['id'] ?? 'opción');
                $out[] = "{$i}) {$label}";
                $i++;
            }
            $this->sendOutbound($instance, $conversation, $phone, implode("\n", $out), 'text', $node->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAiNode(
        WhatsappInstance $instance,
        Conversation $conversation,
        Flow $flow,
        FlowNode $node,
        Lead $lead,
        string $phone,
        string $userMessage
    ): array {
        $config = $node->config ?? [];
        $tags = $config['knowledge_tags'] ?? [];

        $knowledge = KnowledgeItem::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('is_active', true)
            ->get()
            ->filter(function (KnowledgeItem $item) use ($tags) {
                if (empty($tags)) {
                    return true;
                }

                return count(array_intersect($tags, $item->tags ?? [])) > 0;
            })
            ->values();

        $transitions = $this->flowRunner->availableTransitions($flow, $node);

        try {
            $decision = $this->luna->decide([
                'tenant_id' => $instance->tenant_id,
                'conversation_id' => $conversation->id,
                'user_message' => $userMessage !== '' ? $userMessage : 'hola',
                'current_node' => [
                    'type' => $node->type,
                    'name' => $node->name,
                    'config' => empty($config) ? new \stdClass() : $config,
                ],
                'allowed_knowledge' => $knowledge->map(fn (KnowledgeItem $item) => [
                    'title' => $item->title,
                    'content' => $item->content,
                    'tags' => $item->tags ?? [],
                ])->all(),
                'lead_context' => [
                    'name' => $lead->name,
                    'phone' => $lead->phone_e164,
                ],
                'available_transitions' => $transitions,
                'system_hint' => $config['system_hint'] ?? null,
                'min_confidence' => $config['min_confidence'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::error('Luna decide failed', ['error' => $e->getMessage()]);
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                'Tuve un problema para responder. ¿Quieres hablar con una persona?',
                'text',
                $node->id
            );

            return ['ok' => false, 'error' => 'luna_down'];
        }

        $reply = (string) ($decision['reply_text'] ?? '');
        if ($reply !== '') {
            $this->sendOutbound($instance, $conversation, $phone, $reply, 'text', $node->id);
        }

        $chosen = $decision['chosen_transition'] ?? null;
        if ($chosen) {
            $advanced = $this->flowRunner->advance($flow, $node, 'ai_transition', (string) $chosen);
            if ($advanced['node']) {
                return $this->executeNodeChain(
                    $instance,
                    $conversation,
                    $flow,
                    $lead,
                    $phone,
                    $advanced['node']
                );
            }
        }

        return ['ok' => true, 'action' => 'ai_reply', 'luna' => $decision];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sendPaymentQr(
        WhatsappInstance $instance,
        Conversation $conversation,
        Lead $lead,
        string $phone,
        FlowNode $node,
        array $config
    ): void {
        $courseId = $config['course_id'] ?? null;
        $course = $courseId
            ? Course::query()->where('tenant_id', $instance->tenant_id)->with('paymentQr')->find($courseId)
            : Course::query()->where('tenant_id', $instance->tenant_id)->where('is_active', true)->with('paymentQr')->first();

        if (! $course) {
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                'Aún no hay un curso configurado para cobrar.',
                'text',
                $node->id
            );

            return;
        }

        $sale = Sale::create([
            'tenant_id' => $instance->tenant_id,
            'uuid' => (string) Str::uuid(),
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'course_id' => $course->id,
            'amount' => $course->price,
            'currency' => $course->currency,
            'status' => 'pending_payment',
        ]);

        $payment = Payment::create([
            'tenant_id' => $instance->tenant_id,
            'sale_id' => $sale->id,
            'provider' => $config['provider'] ?? 'manual_qr',
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'pending',
            'amount' => $course->price,
            'currency' => $course->currency,
            'qr_payload' => 'MANUAL-QR-'.$sale->uuid,
            'qr_media_asset_id' => $course->payment_qr_media_asset_id,
            'expires_at' => now()->addMinutes((int) ($config['ttl_minutes'] ?? 60)),
        ]);

        $caption = (string) ($config['caption'] ?? 'Escanea el QR para pagar.');
        $text = "💳 *Pago pendiente*\n{$caption}\n\n"
            ."*Curso:* {$course->title}\n"
            ."*Monto:* {$course->price} {$course->currency}\n"
            ."*Ref:* {$payment->idempotency_key}\n\n"
            .'Cuando pagues, envíame la *foto del comprobante* por aquí.';

        $qrAsset = $course->paymentQr;
        $mediaService = app(WhatsAppMediaService::class);
        $dataUri = $qrAsset ? $mediaService->toDataUri($qrAsset) : null;

        if ($dataUri) {
            try {
                $response = $this->evolution->sendMedia(
                    $instance->evolution_instance,
                    $phone,
                    $dataUri,
                    'image',
                    $text,
                    'qr-pago.'.(str_contains($qrAsset->mime, 'png') ? 'png' : 'jpg'),
                    $qrAsset->mime
                );
                $this->storeOutbound($instance, $conversation, $node->id, 'image', $text, $response);
            } catch (Throwable $e) {
                Log::warning('No se pudo enviar imagen QR, fallback texto', ['error' => $e->getMessage()]);
                $this->sendOutbound($instance, $conversation, $phone, $text, 'text', $node->id);
            }
        } else {
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                $text."\n\n_(Sube tu QR en Cursos para enviarlo como imagen.)_",
                'text',
                $node->id
            );
        }

        $conversation->forceFill([
            'status' => 'waiting_payment',
            'context' => array_merge($conversation->context ?? [], [
                'sale_id' => $sale->id,
                'payment_id' => $payment->id,
                'course_id' => $course->id,
            ]),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function deliverCourse(
        WhatsappInstance $instance,
        Conversation $conversation,
        Lead $lead,
        string $phone,
        FlowNode $node,
        array $config
    ): void {
        $courseId = $config['course_id'] ?? data_get($conversation->context, 'course_id');
        $course = $courseId
            ? Course::query()->find($courseId)
            : Course::query()->where('tenant_id', $instance->tenant_id)->where('is_active', true)->first();

        $url = data_get($course?->delivery_payload, 'url', 'https://ejemplo.com/curso');
        $success = (string) ($config['success_text'] ?? '¡Listo! Aquí tienes tu acceso:');
        $text = "{$success}\n\n🔗 {$url}";

        $this->sendOutbound($instance, $conversation, $phone, $text, 'text', $node->id);

        if ($saleId = data_get($conversation->context, 'sale_id')) {
            Sale::query()->where('id', $saleId)->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'paid_at' => now(),
            ]);
        } elseif ($conversation->lead_id) {
            Sale::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('status', ['paid', 'awaiting_confirmation', 'pending_payment'])
                ->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'paid_at' => now(),
                ]);
        }

        $conversation->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();
    }

    private function sendOutbound(
        WhatsappInstance $instance,
        Conversation $conversation,
        string $phone,
        string $text,
        string $type = 'text',
        ?int $nodeId = null
    ): void {
        try {
            $response = $this->evolution->sendText($instance->evolution_instance, $phone, $text);
            $this->storeOutbound($instance, $conversation, $nodeId, $type, $text, $response);
        } catch (Throwable $e) {
            Log::error('Evolution sendText failed', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);
            Message::create([
                'tenant_id' => $instance->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => $type,
                'body' => $text,
                'payload' => ['error' => $e->getMessage()],
                'flow_node_id' => $nodeId,
                'status' => 'failed',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function storeOutbound(
        WhatsappInstance $instance,
        Conversation $conversation,
        ?int $nodeId,
        string $type,
        string $text,
        array $response
    ): void {
        Message::create([
            'tenant_id' => $instance->tenant_id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => $type,
            'body' => $text,
            'payload' => $response,
            'wa_message_id' => data_get($response, 'key.id') ?? data_get($response, 'messageId'),
            'flow_node_id' => $nodeId,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function resolveConversation(WhatsappInstance $instance, Lead $lead): ?Conversation
    {
        $open = Conversation::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('lead_id', $lead->id)
            ->whereIn('status', ['open', 'waiting_input', 'waiting_payment', 'handed_off'])
            ->latest('id')
            ->first();

        if ($open) {
            return $open;
        }

        $flow = Flow::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('status', 'published')
            ->where('is_default', true)
            ->first()
            ?: Flow::query()
                ->where('tenant_id', $instance->tenant_id)
                ->where('status', 'published')
                ->latest('id')
                ->first();

        if (! $flow) {
            return null;
        }

        return Conversation::create([
            'tenant_id' => $instance->tenant_id,
            'lead_id' => $lead->id,
            'whatsapp_instance_id' => $instance->id,
            'flow_id' => $flow->id,
            'flow_version' => $flow->version,
            'current_node_id' => $flow->start_node_id,
            'status' => 'open',
            'context' => [],
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchButtonByLabel(FlowNode $node, string $text): ?string
    {
        $buttons = $node->config['buttons'] ?? [];
        $needle = mb_strtolower(trim($text));
        $needleNorm = preg_replace('/\s+/', ' ', $needle) ?? $needle;

        // "1" / "1)" / "opcion 1" → primer botón
        if (preg_match('/^(\d+)\s*\)?$/', $needleNorm, $m)) {
            $idx = ((int) $m[1]) - 1;
            if (isset($buttons[$idx]['id']) && $buttons[$idx]['id'] !== '') {
                return (string) $buttons[$idx]['id'];
            }
        }

        foreach ($buttons as $button) {
            $label = mb_strtolower(trim((string) ($button['label'] ?? '')));
            $id = (string) ($button['id'] ?? '');
            $labelNorm = preg_replace('/\s+/', ' ', $label) ?? $label;

            if ($labelNorm !== '' && ($needleNorm === $labelNorm || str_contains($needleNorm, $labelNorm) || str_contains($labelNorm, $needleNorm))) {
                return $id !== '' ? $id : null;
            }
            if ($id !== '' && $needleNorm === mb_strtolower($id)) {
                return $id;
            }
            if ($id !== '' && str_contains($needleNorm, mb_strtolower($id))) {
                return $id;
            }
        }

        return null;
    }

    private function statusForNode(FlowNode $node): string
    {
        return match ($node->type) {
            'wait_payment', 'send_payment_qr' => 'waiting_payment',
            'handoff' => 'handed_off',
            'buttons', 'list', 'ai_reply', 'collect_data' => 'waiting_input',
            default => 'open',
        };
    }
}
