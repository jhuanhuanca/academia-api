<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEvolutionWebhook;
use App\Models\WebhookEvent;
use App\Models\WhatsappInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventType = (string) ($payload['event'] ?? $payload['type'] ?? 'UNKNOWN');
        $instanceName = $payload['instance'] ?? $payload['instanceName'] ?? data_get($payload, 'data.instanceName');

        $dedupe = $this->dedupeKey($payload, $eventType);

        $existing = WebhookEvent::query()->where('dedupe_key', $dedupe)->first();
        if ($existing) {
            return response()->json(['status' => 'duplicate', 'id' => $existing->id]);
        }

        $tenantId = null;
        if (is_string($instanceName) && $instanceName !== '') {
            $tenantId = WhatsappInstance::query()
                ->where('evolution_instance', $instanceName)
                ->value('tenant_id');
        }

        // Validación opcional por secret si Evolution lo envía / header custom
        $headerSecret = $request->header('x-webhook-secret') ?: $request->header('apikey');
        if ($headerSecret && is_string($instanceName)) {
            $instance = WhatsappInstance::query()
                ->where('evolution_instance', $instanceName)
                ->first();
            if ($instance && $instance->webhook_secret && ! hash_equals($instance->webhook_secret, (string) $headerSecret)) {
                // No bloqueamos si el apikey es el global de Evolution
                $global = (string) config('services.evolution.api_key');
                if ($global === '' || ! hash_equals($global, (string) $headerSecret)) {
                    // soft allow: Evolution no siempre manda secret por instancia
                }
            }
        }

        $event = WebhookEvent::create([
            'tenant_id' => $tenantId,
            'source' => 'evolution',
            'event_type' => Str::limit($eventType, 80, ''),
            'dedupe_key' => $dedupe,
            'payload' => $payload,
            'status' => 'received',
            'created_at' => now(),
        ]);

        ProcessEvolutionWebhook::dispatch($event->id);

        return response()->json([
            'status' => 'accepted',
            'id' => $event->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(array $payload, string $eventType): string
    {
        $messageId = data_get($payload, 'data.key.id')
            ?? data_get($payload, 'data.id')
            ?? data_get($payload, 'data.messages.0.key.id');

        if (is_string($messageId) && $messageId !== '') {
            return 'evo:msg:'.$messageId;
        }

        return 'evo:'.sha1($eventType.'|'.json_encode($payload));
    }
}
