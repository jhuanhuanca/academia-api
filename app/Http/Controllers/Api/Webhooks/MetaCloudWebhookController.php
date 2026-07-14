<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaCloudWebhook;
use App\Models\WebhookEvent;
use App\Models\WhatsappInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaCloudWebhookController extends Controller
{
    /**
     * Verificación del webhook (Meta challenge).
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $query = $request->query();
        $mode = (string) ($query['hub.mode'] ?? $query['hub_mode'] ?? '');
        $token = (string) ($query['hub.verify_token'] ?? $query['hub_verify_token'] ?? '');
        $challenge = (string) ($query['hub.challenge'] ?? $query['hub_challenge'] ?? '');

        $expected = (string) config('services.meta_whatsapp.verify_token', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Meta webhook verify falló', [
            'mode' => $mode,
            'token_match' => $expected !== '' && hash_equals($expected, $token),
        ]);

        return response()->json(['message' => 'Forbidden'], 403);
    }

    /**
     * Eventos entrantes Cloud API.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->signatureValid($request)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return response()->json(['status' => 'ignored', 'reason' => 'not_whatsapp']);
        }

        $phoneNumberId = (string) (
            data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id') ?: ''
        );

        $tenantId = null;
        if ($phoneNumberId !== '') {
            $tenantId = WhatsappInstance::query()
                ->where('integration', 'meta_cloud')
                ->where('meta_phone_number_id', $phoneNumberId)
                ->value('tenant_id');
        }

        $dedupe = $this->dedupeKey($payload);
        $existing = WebhookEvent::query()->where('dedupe_key', $dedupe)->first();
        if ($existing) {
            return response()->json(['status' => 'duplicate', 'id' => $existing->id]);
        }

        $eventType = (string) (
            data_get($payload, 'entry.0.changes.0.field') ?: 'messages'
        );

        $event = WebhookEvent::create([
            'tenant_id' => $tenantId,
            'source' => 'meta_cloud',
            'event_type' => Str::limit($eventType, 80, ''),
            'dedupe_key' => $dedupe,
            'payload' => $payload,
            'status' => 'received',
            'created_at' => now(),
        ]);

        ProcessMetaCloudWebhook::dispatch($event->id);

        return response()->json([
            'status' => 'accepted',
            'id' => $event->id,
        ]);
    }

    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('services.meta_whatsapp.app_secret', '');
        if ($secret === '') {
            // Sin secret configurado: permitir en staging; en prod conviene setearlo.
            return ! app()->environment('production')
                || (bool) config('services.meta_whatsapp.allow_unsigned_webhooks', false);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(array $payload): string
    {
        $messageId = data_get($payload, 'entry.0.changes.0.value.messages.0.id')
            ?? data_get($payload, 'entry.0.changes.0.value.statuses.0.id');

        if (is_string($messageId) && $messageId !== '') {
            return 'meta:msg:'.$messageId;
        }

        return 'meta:'.sha1(json_encode($payload) ?: uniqid('meta_', true));
    }
}
