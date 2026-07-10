<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappInstance;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\EvolutionClient;
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
            ->makeHidden(['evolution_apikey', 'webhook_secret']);

        return response()->json(['data' => $instances]);
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
            'evolution_instance' => ['required', 'string', 'max:100'],
            'integration' => ['nullable', 'in:baileys,business'],
        ]);

        $instance = WhatsappInstance::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'evolution_instance' => $data['evolution_instance'],
            'integration' => $data['integration'] ?? 'baileys',
            'status' => 'disconnected',
            'webhook_secret' => Str::random(48),
            'meta' => [],
        ]);

        return response()->json(['data' => $this->present($instance)], 201);
    }

    /**
     * Crea/conecta instancia real en Evolution y devuelve QR.
     */
    public function connect(Request $request, WhatsappInstance $whatsappInstance, WhatsAppProvisioner $provisioner): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

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

    public function refresh(Request $request, WhatsappInstance $whatsappInstance, WhatsAppProvisioner $provisioner): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

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
            ],
        ]);
    }

    /**
     * Simulación local sin Evolution (solo para UI/dev).
     */
    /**
     * Simula un mensaje entrante sin Evolution (dev/QA).
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
            'wa_name' => $data['wa_name'] ?? 'Simulado',
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

        return response()->json([
            'data' => $result,
            'incoming' => $incoming,
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
            'message' => 'Conexión demo. Para producción usa POST .../connect con Evolution.',
        ]);
    }

    public function health(EvolutionClient $evolution): JsonResponse
    {
        return response()->json(['data' => $evolution->health()]);
    }

    /**
     * Endpoint de prueba: envía un texto usando Evolution.
     */
    public function testSend(Request $request, WhatsappInstance $whatsappInstance, EvolutionClient $evolution): JsonResponse
    {
        abort_if($request->user()->tenant_id !== $whatsappInstance->tenant_id, 404);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = $evolution->sendText(
                $whatsappInstance->evolution_instance,
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
            'phone_e164' => $instance->phone_e164,
            'status' => $instance->status,
            'meta' => [
                'last_qr' => data_get($instance->meta, 'last_qr'),
            ],
            'last_connected_at' => $instance->last_connected_at,
            'created_at' => $instance->created_at,
            'updated_at' => $instance->updated_at,
        ];
    }
}
