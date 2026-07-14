<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\MetaCloudClient;
use App\Services\WhatsApp\WhatsAppMessengerResolver;
use App\Services\WhatsApp\WhatsAppProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class WhatsappInstanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $instances = WhatsappInstance::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->get()
            ->makeHidden(['evolution_apikey', 'webhook_secret', 'meta_access_token']);

        return response()->json(['data' => $instances->map(fn (WhatsappInstance $i) => $this->present($i))->values()]);
    }

    public function show(Request $request, WhatsappInstance $whatsappInstance): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        return response()->json([
            'data' => $this->present($whatsappInstance),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'evolution_instance' => ['nullable', 'string', 'max:100'],
            'integration' => ['nullable', 'in:baileys,business,meta_cloud'],
            'meta_phone_number_id' => ['nullable', 'string', 'max:64'],
            'meta_waba_id' => ['nullable', 'string', 'max:64'],
            'meta_access_token' => ['nullable', 'string', 'max:2000'],
            'phone_e164' => ['nullable', 'string', 'max:20'],
        ]);

        $integration = $data['integration'] ?? 'meta_cloud';
        $evoName = $data['evolution_instance']
            ?? 'wa-'.Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        if (! in_array($integration, ['meta_cloud', 'baileys', 'business'], true)) {
            $integration = 'meta_cloud';
        }

        if ($integration !== 'meta_cloud' && (! is_string($evoName) || $evoName === '')) {
            return response()->json(['message' => 'evolution_instance es obligatorio para Baileys/Business'], 422);
        }

        $instance = WhatsappInstance::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'evolution_instance' => $evoName,
            'integration' => $integration,
            'meta_phone_number_id' => $data['meta_phone_number_id'] ?? config('services.meta_whatsapp.phone_number_id'),
            'meta_waba_id' => $data['meta_waba_id'] ?? config('services.meta_whatsapp.waba_id'),
            'meta_access_token' => $data['meta_access_token'] ?? null,
            'phone_e164' => $data['phone_e164'] ?? null,
            'status' => $integration === 'meta_cloud' ? 'disconnected' : 'disconnected',
            'webhook_secret' => Str::random(48),
            'meta' => [],
        ]);

        return response()->json(['data' => $this->present($instance)], 201);
    }

    public function update(Request $request, WhatsappInstance $whatsappInstance): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'integration' => ['sometimes', 'in:baileys,business,meta_cloud'],
            'meta_phone_number_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'meta_waba_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'meta_access_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone_e164' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:disconnected,connecting,open,close'],
        ]);

        $whatsappInstance->fill($data)->save();

        return response()->json(['data' => $this->present($whatsappInstance->fresh())]);
    }

    /**
     * Baileys: crea/conecta Evolution + QR.
     * Meta Cloud: valida credenciales Graph y marca open.
     */
    public function connect(
        Request $request,
        WhatsappInstance $whatsappInstance,
        WhatsAppProvisioner $provisioner,
        MetaCloudClient $meta
    ): JsonResponse {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        if ($whatsappInstance->usesMetaCloud()) {
            $data = $request->validate([
                'meta_phone_number_id' => ['nullable', 'string', 'max:64'],
                'meta_waba_id' => ['nullable', 'string', 'max:64'],
                'meta_access_token' => ['nullable', 'string', 'max:2000'],
                'phone_e164' => ['nullable', 'string', 'max:20'],
            ]);

            if (! empty($data)) {
                $whatsappInstance->fill(array_filter($data, fn ($v) => $v !== null && $v !== ''))->save();
                $whatsappInstance->refresh();
            }

            // Rellenar desde .env si faltan en la instancia
            if (! $whatsappInstance->meta_phone_number_id && config('services.meta_whatsapp.phone_number_id')) {
                $whatsappInstance->meta_phone_number_id = config('services.meta_whatsapp.phone_number_id');
            }
            if (! $whatsappInstance->meta_access_token && config('services.meta_whatsapp.access_token')) {
                $whatsappInstance->meta_access_token = config('services.meta_whatsapp.access_token');
            }
            $whatsappInstance->save();

            $health = $meta->health($whatsappInstance);
            if (($health['status'] ?? '') !== 'ok') {
                return response()->json([
                    'message' => 'No se pudo validar Meta Cloud API',
                    'hint' => 'Revisa el Phone Number ID y el Access Token de esta instancia',
                    'health' => $health,
                ], 503);
            }

            $display = (string) data_get($health, 'body.display_phone_number', '');
            $whatsappInstance->update([
                'status' => 'open',
                'phone_e164' => $whatsappInstance->phone_e164
                    ?: ($display !== '' ? '+'.preg_replace('/\D+/', '', $display) : null),
                'last_connected_at' => now(),
            ]);

            return response()->json([
                'data' => $this->present($whatsappInstance->fresh()),
                'qr' => null,
                'meta_cloud' => $health,
                'message' => 'WhatsApp Cloud API conectada.',
            ]);
        }

        try {
            $result = $provisioner->ensureAndConnect($whatsappInstance);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'No se pudo conectar con Evolution: '.$e->getMessage(),
                'hint' => 'Verifica EVOLUTION_BASE_URL, EVOLUTION_API_KEY y que Evolution esté arriba en :8080',
            ], 503);
        }

        return response()->json([
            'data' => $this->present($result['instance']),
            'qr' => $result['qr'],
            'evolution' => $result['evolution'],
        ]);
    }

    public function refresh(
        Request $request,
        WhatsappInstance $whatsappInstance,
        WhatsAppProvisioner $provisioner,
        MetaCloudClient $meta
    ): JsonResponse {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        if ($whatsappInstance->usesMetaCloud()) {
            $health = $meta->health($whatsappInstance);
            $ok = ($health['status'] ?? '') === 'ok';
            $whatsappInstance->update([
                'status' => $ok ? 'open' : 'disconnected',
                'last_connected_at' => $ok ? now() : $whatsappInstance->last_connected_at,
            ]);

            return response()->json([
                'data' => $this->present($whatsappInstance->fresh()),
                'state' => $health,
            ]);
        }

        $result = $provisioner->refreshStatus($whatsappInstance);

        return response()->json([
            'data' => $this->present($result['instance']),
            'state' => $result['state'],
        ]);
    }

    public function qr(Request $request, WhatsappInstance $whatsappInstance): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        return response()->json([
            'data' => [
                'status' => $whatsappInstance->status,
                'qr' => data_get($whatsappInstance->meta, 'last_qr'),
                'integration' => $whatsappInstance->integration,
            ],
        ]);
    }

    /**
     * Vista previa del flujo: mensaje entrante + hilo de la conversación.
     */
    public function simulateInbound(Request $request, WhatsappInstance $whatsappInstance, ConversationOrchestrator $orchestrator): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'text' => ['nullable', 'string', 'max:4000'],
            'button_id' => ['nullable', 'string', 'max:80'],
            'wa_name' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:text,image,document,button_reply'],
            'receipt_base64' => ['nullable', 'string'],
        ]);

        $digits = preg_replace('/\D+/', '', $data['phone']) ?? '';
        $phone = '+'.$digits;

        $incoming = [
            'wa_message_id' => 'sim_'.Str::uuid(),
            'phone_e164' => $phone,
            'wa_name' => $data['wa_name'] ?? 'Cliente',
            'from_me' => false,
            'type' => ! empty($data['button_id'])
                ? 'button_reply'
                : ($data['type'] ?? 'text'),
            'body' => $data['text'] ?? ($data['button_id'] ?? 'hola'),
            'button_id' => $data['button_id'] ?? null,
            'list_id' => null,
            'receipt_base64' => $data['receipt_base64'] ?? null,
            'raw' => array_filter([
                'simulated' => true,
                'receipt_base64' => $data['receipt_base64'] ?? null,
            ]),
        ];

        $result = $orchestrator->handleIncoming($whatsappInstance, $incoming);

        $thread = [];
        $lead = Lead::query()
            ->where('tenant_id', $whatsappInstance->tenant_id)
            ->where('phone_e164', $phone)
            ->first();

        if ($lead) {
            $conversation = Conversation::query()
                ->where('tenant_id', $whatsappInstance->tenant_id)
                ->where('lead_id', $lead->id)
                ->latest('id')
                ->first();

            if ($conversation) {
                $thread = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->orderBy('id')
                    ->limit(80)
                    ->get(['id', 'direction', 'type', 'body', 'payload', 'created_at', 'status'])
                    ->all();
            }
        }

        return response()->json([
            'data' => $result,
            'incoming' => $incoming,
            'messages' => $thread,
        ]);
    }

    public function connectDemo(Request $request, WhatsappInstance $whatsappInstance): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        $whatsappInstance->update([
            'status' => 'open',
            'phone_e164' => $whatsappInstance->phone_e164 ?: '+580000000000',
            'last_connected_at' => now(),
        ]);

        return response()->json([
            'data' => $this->present($whatsappInstance->fresh()),
            'message' => 'Conexión demo. Para producción usa Cloud API o Evolution.',
        ]);
    }

    public function health(Request $request, MetaCloudClient $meta): JsonResponse
    {
        $instance = WhatsappInstance::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('id')
            ->first();

        $metaHealth = $instance?->usesMetaCloud()
            ? $meta->health($instance)
            : $meta->health();

        return response()->json([
            'data' => [
                'provider' => $instance?->integration ?? 'meta_cloud',
                'status' => $metaHealth['status'] ?? 'unconfigured',
                'meta_cloud' => $metaHealth,
            ],
        ]);
    }

    /**
     * Endpoint de prueba: envía un texto con el messenger de la instancia.
     */
    public function testSend(
        Request $request,
        WhatsappInstance $whatsappInstance,
        WhatsAppMessengerResolver $messengers
    ): JsonResponse {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = $messengers->for($whatsappInstance)->sendText(
                $whatsappInstance,
                $data['number'],
                $data['text']
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $result]);
    }

    private function present(WhatsappInstance $instance): array
    {
        return [
            'id' => $instance->id,
            'tenant_id' => $instance->tenant_id,
            'name' => $instance->name,
            'evolution_instance' => $instance->evolution_instance,
            'integration' => $instance->integration,
            'meta_phone_number_id' => $instance->meta_phone_number_id,
            'meta_waba_id' => $instance->meta_waba_id,
            'has_meta_token' => filled($instance->meta_access_token) || filled(config('services.meta_whatsapp.access_token')),
            'phone_e164' => $instance->phone_e164,
            'status' => $instance->status,
            'meta' => [
                'last_qr' => data_get($instance->meta, 'last_qr'),
            ],
            'webhook_url' => $instance->usesMetaCloud()
                ? url('/api/webhooks/meta')
                : url('/api/webhooks/evolution'),
            'last_connected_at' => $instance->last_connected_at,
            'created_at' => $instance->created_at,
            'updated_at' => $instance->updated_at,
        ];
    }
}
