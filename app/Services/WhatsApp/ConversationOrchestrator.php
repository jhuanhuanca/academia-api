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
        private readonly WhatsAppMessengerResolver $messengers,
        private readonly FlowRunner $flowRunner,
        private readonly LunaClient $luna,
        private readonly WhatsAppMediaService $mediaService,
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

        // Preferir destino real (remoteJidAlt) o JID @lid; si no, el phone_e164
        $replyTo = (string) ($incoming['reply_to'] ?? ltrim($phone, '+'));

        return DB::transaction(function () use ($instance, $incoming, $phone, $replyTo) {
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
                $this->messengers->for($instance)->sendText(
                    $instance,
                    $replyTo,
                    'Hola, aún no hay un flujo publicado en MarketLuna. Configura uno en el dashboard.'
                );

                return ['skipped' => true, 'reason' => 'no_flow'];
            }

            // Guardar destino de respuesta (evita exists:false con @lid)
            $conversation->forceFill([
                'context' => array_merge($conversation->context ?? [], [
                    'reply_to' => $replyTo,
                ]),
            ])->save();

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
            // Nunca reiniciar si está en menú de botones esperando una opción (1, 2, qr…)
            $bodyText = (string) ($incoming['body'] ?? '');
            if (
                $current
                && in_array($conversation->status, ['waiting_payment', 'handed_off'], true)
                && ! ($current->type === 'buttons' || $current->type === 'list')
                && $this->looksLikeRestart($bodyText)
                && ! $this->looksLikeMenuChoice($bodyText)
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

                return $this->runFromStart($instance, $conversation, $flow, $lead, $replyTo);
            }

            // Primera entrada o nodo start inválido/borrado → cadena desde inicio
            // Si ya hay menú activo (waiting_input + buttons), NO reiniciar aunque el nodo falle
            if ((! $current || $current->type === 'start') && $conversation->status !== 'waiting_input') {
                return $this->runFromStart($instance, $conversation, $flow, $lead, $replyTo);
            }

            if (! $current || $current->type === 'start') {
                // Recuperar menú de botones si la conversación espera input
                $current = $this->resolveWaitingButtonsNode($flow, $conversation) ?? $current;
            }

            if (! $current || $current->type === 'start') {
                return $this->runFromStart($instance, $conversation, $flow, $lead, $replyTo);
            }

            $triggerType = 'default';
            $triggerKey = '';

            if (($incoming['type'] ?? '') === 'button_reply' && ! empty($incoming['button_id'])) {
                $triggerType = 'button';
                $triggerKey = (string) $incoming['button_id'];
                $this->rememberSelectedProduct($conversation, $triggerKey);
            } elseif (($incoming['type'] ?? '') === 'list_reply' && ! empty($incoming['list_id'])) {
                $triggerType = 'list';
                $triggerKey = (string) $incoming['list_id'];
                $this->rememberSelectedProduct($conversation, $triggerKey);
            } elseif ($current && $current->type === 'ai_reply') {
                return $this->handleAiNode($instance, $conversation, $flow, $current, $lead, $phone, $bodyText);
            } elseif ($current && in_array($current->type, ['buttons', 'list'], true) && trim($bodyText) !== '') {
                $matched = $this->matchButtonByLabel($current, $bodyText);
                if ($matched) {
                    $triggerType = 'button';
                    $triggerKey = $matched;
                    $this->rememberSelectedProduct($conversation, $matched);
                } else {
                    $this->sendOutbound(
                        $instance,
                        $conversation,
                        $phone,
                        'Por favor responde con el *número* de la opción (1, 2, 3…) o el nombre (ej. QR, Tigo).',
                        'text',
                        $current->id
                    );

                    return ['ok' => true, 'action' => 'reprompt_buttons', 'body' => $bodyText];
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
                $triggerType = 'default';
                $triggerKey = '';
            }

            $advanced = $this->flowRunner->advance($flow, $current, $triggerType, $triggerKey);
            $next = $advanced['node'];
            $edge = $advanced['edge'] ?? null;

            // Botón elegido pero sin conexión configurada → no reejecutar el menú ni reiniciar
            if ($triggerType === 'button' && ! $edge) {
                $this->sendOutbound(
                    $instance,
                    $conversation,
                    $phone,
                    "Elegiste *{$triggerKey}*, pero esa opción aún no está conectada en el flujo. Revisa Flow Builder (conexiones del botón).",
                    'text',
                    $current->id
                );

                return ['ok' => false, 'action' => 'button_without_edge', 'trigger_key' => $triggerKey];
            }

            if (! $next || ($edge === null && $triggerType === 'button')) {
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

            // Evitar re-ejecutar el mismo nodo de botones (parecía un “reinicio”)
            if ($next->id === $current->id && in_array($current->type, ['buttons', 'list'], true)) {
                $this->sendOutbound(
                    $instance,
                    $conversation,
                    $phone,
                    'Por favor elige una de las opciones del menú con el *número* o el nombre 🙂',
                    'text',
                    $current->id
                );

                return ['ok' => true, 'action' => 'same_node_reprompt'];
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
        if ($text === '' || $this->looksLikeMenuChoice($text)) {
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

    private function looksLikeMenuChoice(string $body): bool
    {
        $text = $this->normalizeMenuText($body);
        if ($text === '') {
            return false;
        }

        if (preg_match('/^\d{1,2}([).:\-]|️⃣)?$/u', $text)) {
            return true;
        }

        if (preg_match('/^(opci[oó]n|option|n[uú]mero|#)\s*\d{1,2}$/u', $text)) {
            return true;
        }

        return false;
    }

    private function normalizeMenuText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        $keycaps = [
            '1️⃣' => '1', '2️⃣' => '2', '3️⃣' => '3', '4️⃣' => '4', '5️⃣' => '5',
            '6️⃣' => '6', '7️⃣' => '7', '8️⃣' => '8', '9️⃣' => '9', '🔟' => '10',
            '1⃣' => '1', '2⃣' => '2', '3⃣' => '3', '4⃣' => '4', '5⃣' => '5', '6⃣' => '6',
        ];
        $text = strtr($text, $keycaps);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function resolveWaitingButtonsNode(Flow $flow, Conversation $conversation): ?FlowNode
    {
        if ($conversation->status !== 'waiting_input') {
            return null;
        }

        return FlowNode::query()
            ->where('flow_id', $flow->id)
            ->whereIn('type', ['buttons', 'list'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buttonList(FlowNode $node): array
    {
        $config = is_array($node->config) ? $node->config : [];

        // Catálogo dinámico desde productos activos del tenant
        if (($config['source'] ?? '') === 'courses') {
            $flow = Flow::query()->find($node->flow_id);
            if (! $flow) {
                return [];
            }

            return Course::query()
                ->where('tenant_id', $flow->tenant_id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(6)
                ->get()
                ->map(function (Course $course) {
                    $price = rtrim(rtrim(number_format((float) $course->price, 2, '.', ''), '0'), '.');
                    $short = $course->title;
                    if (mb_strlen($short) > 20) {
                        $short = mb_substr($short, 0, 19).'…';
                    }

                    return [
                        'id' => 'course_'.$course->id,
                        'label' => $short,
                        'display' => "{$course->title} — {$price} {$course->currency}",
                    ];
                })
                ->all();
        }

        $buttons = $config['buttons'] ?? [];
        if (! is_array($buttons)) {
            return [];
        }

        $list = [];
        foreach ($buttons as $button) {
            if (is_array($button)) {
                $list[] = $button;
            }
        }

        return array_values($list);
    }

    private function rememberSelectedProduct(Conversation $conversation, string $buttonId): void
    {
        if (! preg_match('/^course_(\d+)$/', $buttonId, $m)) {
            return;
        }

        $courseId = (int) $m[1];
        $conversation->forceFill([
            'context' => array_merge($conversation->context ?? [], [
                'selected_course_id' => $courseId,
                'course_id' => $courseId,
            ]),
        ])->save();
    }

    private function resolveSaleCourseId(WhatsappInstance $instance, Conversation $conversation, array $config): ?int
    {
        $fromContext = data_get($conversation->context, 'selected_course_id')
            ?? data_get($conversation->context, 'course_id');
        if ($fromContext) {
            return (int) $fromContext;
        }

        if (! empty($config['course_id'])) {
            return (int) $config['course_id'];
        }

        $fallback = Course::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        return $fallback ? (int) $fallback : null;
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
            'send_media' => $this->sendMediaNode($instance, $conversation, $phone, $node, $config),
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
    private function sendMediaNode(
        WhatsappInstance $instance,
        Conversation $conversation,
        string $phone,
        FlowNode $node,
        array $config
    ): void {
        $assetId = (int) ($config['media_asset_id'] ?? 0);
        $caption = trim((string) ($config['caption'] ?? $config['text'] ?? ''));
        $mediaType = (string) ($config['media_type'] ?? 'image');
        if (! in_array($mediaType, ['image', 'video'], true)) {
            $mediaType = 'image';
        }

        if ($assetId <= 0) {
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                $caption !== '' ? $caption : '⚠️ Falta subir la imagen/video en este nodo del flujo.',
                'text',
                $node->id
            );

            return;
        }

        $asset = \App\Models\MediaAsset::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('id', $assetId)
            ->first();

        if (! $asset) {
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                '⚠️ El archivo del nodo ya no existe. Vuelve a subirlo en Flow Builder.',
                'text',
                $node->id
            );

            return;
        }

        try {
            $response = $this->mediaService->sendMediaAsset(
                $instance,
                $this->resolveDestination($conversation, $phone),
                $asset,
                $caption,
                $mediaType
            );
            $this->storeOutbound(
                $instance,
                $conversation,
                $node->id,
                $mediaType,
                $caption !== '' ? $caption : '['.$mediaType.']',
                $response
            );
        } catch (Throwable $e) {
            Log::error('Fallo envío send_media', [
                'error' => $e->getMessage(),
                'asset_id' => $asset->id,
                'media_type' => $mediaType,
            ]);
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                $caption !== ''
                    ? $caption."\n\n_(No pude adjuntar el archivo; un asesor te lo reenvía.)_"
                    : '⚠️ No pude enviar el archivo multimedia. Pide *humano* si lo necesitas.',
                'text',
                $node->id
            );
        }
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
        $buttons = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];
        $footer = is_string($config['footer'] ?? null) ? $config['footer'] : 'MarketLuna';
        $preferNative = (bool) ($config['native_buttons'] ?? false)
            || $this->messengers->for($instance)->supportsNativeButtons();
        $destination = $this->resolveDestination($conversation, $phone);

        $normalizedButtons = [];
        foreach (array_slice($buttons, 0, 3) as $b) {
            if (! is_array($b)) {
                continue;
            }
            $id = (string) ($b['id'] ?? uniqid('btn_', true));
            $label = (string) ($b['display'] ?? $b['label'] ?? $b['displayText'] ?? $id);
            $normalizedButtons[] = [
                'id' => $id,
                'label' => $label,
                'display' => $label,
            ];
        }

        // Cloud API: botones nativos (máx. 3). Baileys: menú numerado salvo native_buttons.
        if ($preferNative && $normalizedButtons !== []) {
            $lines = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
            $titleLine = trim((string) ($lines[0] ?? 'Opciones'));
            $description = count($lines) > 1
                ? trim(implode("\n", array_slice($lines, 1)))
                : $text;

            try {
                $response = $this->messengers->for($instance)->sendButtons(
                    $instance,
                    $destination,
                    $titleLine !== '' ? $titleLine : 'Opciones',
                    $description !== '' ? $description : $text,
                    $normalizedButtons,
                    $footer
                );
                $this->storeOutbound(
                    $instance,
                    $conversation,
                    $node->id,
                    'buttons',
                    $text,
                    array_merge($response, ['buttons' => $normalizedButtons, 'footer' => $footer])
                );

                return;
            } catch (Throwable $e) {
                Log::warning('sendButtons nativo falló', [
                    'error' => $e->getMessage(),
                    'integration' => $instance->integration,
                ]);

                // Meta Cloud: no degradar a números; guardar botones para UI / reintento
                if ($instance->usesMetaCloud()) {
                    $this->storeOutbound(
                        $instance,
                        $conversation,
                        $node->id,
                        'buttons',
                        $text,
                        [
                            'preview' => true,
                            'buttons' => $normalizedButtons,
                            'footer' => $footer,
                            'error' => $e->getMessage(),
                        ]
                    );

                    return;
                }
            }
        }

        // Fallback Baileys / sin botones nativos: menú numerado
        $out = [rtrim($text), '', 'Responde con el *número* o el nombre:'];
        $i = 1;
        foreach (array_slice($buttons, 0, 6) as $b) {
            if (! is_array($b)) {
                continue;
            }
            $label = (string) ($b['display'] ?? $b['label'] ?? $b['id'] ?? 'opción');
            $out[] = "*{$i})* {$label}";
            $i++;
        }
        if ($footer !== '') {
            $out[] = '';
            $out[] = "_ {$footer}_";
        }

        $this->sendOutbound($instance, $conversation, $phone, implode("\n", $out), 'text', $node->id);
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
     * Envía instrucciones de pago (QR imagen y/o texto con cuenta).
     * El QR NO es obligatorio: Tigo/Yape/depósito solo envían datos y esperan comprobante.
     *
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
        $courseId = $this->resolveSaleCourseId($instance, $conversation, $config);
        $course = $courseId
            ? Course::query()->where('tenant_id', $instance->tenant_id)->with('paymentQr')->find($courseId)
            : null;

        if (! $course) {
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                'Aún no hay un producto configurado para cobrar. Crea uno en Productos.',
                'text',
                $node->id
            );

            return;
        }

        $provider = (string) ($config['provider'] ?? 'manual_qr');
        $wantsQrImage = $provider === 'manual_qr'
            && (bool) ($config['send_qr_image'] ?? true);

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

        $qrAsset = $wantsQrImage
            ? $this->resolvePaymentQrAsset($instance->tenant_id, $course, $config)
            : null;

        $payment = Payment::create([
            'tenant_id' => $instance->tenant_id,
            'sale_id' => $sale->id,
            'provider' => $provider,
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'pending',
            'amount' => $course->price,
            'currency' => $course->currency,
            'qr_payload' => strtoupper($provider).'-'.$sale->uuid,
            'qr_media_asset_id' => $qrAsset?->id,
            'expires_at' => now()->addMinutes((int) ($config['ttl_minutes'] ?? 60)),
        ]);

        $text = $this->buildPaymentInstructionsText($course, $payment, $provider, $config);
        $this->sendOutbound($instance, $conversation, $phone, $text, 'text', $node->id);

        if ($wantsQrImage && $qrAsset) {
            try {
                $response = $this->mediaService->sendImage(
                    $instance,
                    $this->resolveDestination($conversation, $phone),
                    $qrAsset,
                    '📲 Escanea este QR para pagar'
                );
                $this->storeOutbound($instance, $conversation, $node->id, 'image', '[qr-pago]', $response);
            } catch (Throwable $e) {
                Log::error('Fallo envío QR imagen', [
                    'error' => $e->getMessage(),
                    'asset_id' => $qrAsset->id,
                    'path' => $qrAsset->path,
                    'disk' => $qrAsset->disk,
                ]);
                $this->sendOutbound(
                    $instance,
                    $conversation,
                    $phone,
                    '⚠️ No pude enviar la imagen del QR. Un asesor te la reenvía en breve, o pide *humano*.',
                    'text',
                    $node->id
                );
            }
        } elseif ($wantsQrImage && ! $qrAsset) {
            Log::warning('Curso sin imagen QR', [
                'course_id' => $course->id,
                'config_qr' => $config['qr_media_asset_id'] ?? null,
            ]);
            $this->sendOutbound(
                $instance,
                $conversation,
                $phone,
                '⚠️ Falta configurar la imagen QR: Dashboard → Cursos → Subir QR de cobro. Mientras tanto usa Tigo/Yape/Depósito.',
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
                'payment_provider' => $provider,
            ]),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildPaymentInstructionsText(
        Course $course,
        Payment $payment,
        string $provider,
        array $config
    ): string {
        $methodLabel = match ($provider) {
            'tigo_money' => 'Tigo Money',
            'yape' => 'Yape',
            'bank_deposit' => 'Depósito bancario',
            'manual_qr' => 'QR de pago',
            default => 'Pago',
        };

        $caption = trim((string) ($config['caption'] ?? ''));
        $instructions = trim((string) ($config['instructions'] ?? ''));

        if ($instructions === '') {
            $instructions = match ($provider) {
                'tigo_money' => "📱 *Tigo Money*\nNúmero: (configura tu número)\nNombre: (configura el titular)",
                'yape' => "💜 *Yape*\nNúmero: (configura tu número)\nNombre: (configura el titular)",
                'bank_deposit' => "🏦 *Depósito bancario*\nBanco: (configura)\nCuenta: (configura)\nTitular: (configura)",
                default => 'Escanea el QR o paga con el método indicado.',
            };
        }

        if ($caption === '') {
            $caption = match ($provider) {
                'manual_qr' => '¡Excelente decisión! Enseguida te envío el QR de pago.',
                default => '¡Excelente decisión! Aquí tienes los datos para pagar con *'.$methodLabel.'*.',
            };
        }

        return "💳 *Pago pendiente — {$methodLabel}*\n"
            ."{$caption}\n\n"
            ."{$instructions}\n\n"
            ."*Curso:* {$course->title}\n"
            ."*Monto:* {$course->price} {$course->currency}\n"
            ."*Ref:* {$payment->idempotency_key}\n\n"
            .'Cuando pagues, envíame la *foto del comprobante* por aquí para activar tu acceso ✅';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolvePaymentQrAsset(int $tenantId, Course $course, array $config): ?\App\Models\MediaAsset
    {
        $fromConfig = $config['qr_media_asset_id'] ?? null;
        if ($fromConfig) {
            $asset = \App\Models\MediaAsset::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $fromConfig)
                ->first();
            if ($asset) {
                return $this->mediaService->healPaymentQrAsset($asset);
            }
        }

        if ($course->paymentQr) {
            return $this->mediaService->healPaymentQrAsset($course->paymentQr);
        }

        $other = Course::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('payment_qr_media_asset_id')
            ->with('paymentQr')
            ->orderByDesc('id')
            ->first();

        if ($other?->paymentQr) {
            return $this->mediaService->healPaymentQrAsset($other->paymentQr);
        }

        return null;
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
        $courseId = $this->resolveSaleCourseId($instance, $conversation, $config);
        $course = $courseId
            ? Course::query()->where('tenant_id', $instance->tenant_id)->find($courseId)
            : null;

        $url = data_get($course?->delivery_payload, 'url', 'https://ejemplo.com/producto');
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
        $destination = $this->resolveDestination($conversation, $phone);

        try {
            $response = $this->messengers->for($instance)->sendText($instance, $destination, $text);
            $this->storeOutbound($instance, $conversation, $nodeId, $type, $text, $response);
        } catch (Throwable $e) {
            Log::error('WhatsApp sendText failed', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'destination' => $destination,
                'integration' => $instance->integration,
            ]);
            Message::create([
                'tenant_id' => $instance->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => $type,
                'body' => $text,
                'payload' => ['error' => $e->getMessage(), 'destination' => $destination],
                'flow_node_id' => $nodeId,
                'status' => 'failed',
                'created_at' => now(),
            ]);
        }
    }

    private function resolveDestination(Conversation $conversation, string $phone): string
    {
        $fromContext = data_get($conversation->context ?? [], 'reply_to');
        if (is_string($fromContext) && trim($fromContext) !== '') {
            return trim($fromContext);
        }

        return $phone;
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
        $buttons = array_slice($this->buttonList($node), 0, 6);
        if ($buttons === []) {
            return null;
        }

        $needleNorm = $this->normalizeMenuText($text);

        // "1" / "1)" / "1." / "opción 1" / "1️⃣"
        if (
            preg_match('/^(?:opci[oó]n|option|n[uú]mero|#)\s*(\d{1,2})$/u', $needleNorm, $m)
            || preg_match('/^(\d{1,2})\s*[).:\-]*$/u', $needleNorm, $m)
        ) {
            $idx = ((int) $m[1]) - 1;
            if (isset($buttons[$idx])) {
                $id = trim((string) ($buttons[$idx]['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }

        foreach ($buttons as $button) {
            $label = $this->normalizeMenuText((string) ($button['label'] ?? ''));
            $id = trim((string) ($button['id'] ?? ''));
            $idNorm = mb_strtolower($id);

            $labelPlain = trim(preg_replace('/\p{So}|\p{Sk}/u', '', $label) ?? $label);
            $labelPlain = trim(preg_replace('/\s+/u', ' ', $labelPlain) ?? $labelPlain);

            if ($idNorm !== '' && ($needleNorm === $idNorm || str_contains($needleNorm, $idNorm))) {
                return $id;
            }

            if ($labelPlain !== '' && (
                $needleNorm === $labelPlain
                || str_contains($labelPlain, $needleNorm)
                || str_contains($needleNorm, $labelPlain)
            )) {
                return $id !== '' ? $id : null;
            }

            if ($label !== '' && ($needleNorm === $label || str_contains($label, $needleNorm))) {
                return $id !== '' ? $id : null;
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
