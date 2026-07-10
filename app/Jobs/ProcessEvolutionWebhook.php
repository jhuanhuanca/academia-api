<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Models\WhatsappInstance;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEvolutionWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $webhookEventId)
    {
    }

    public function handle(
        IncomingMessageNormalizer $normalizer,
        ConversationOrchestrator $orchestrator
    ): void {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if (! $event || $event->status === 'processed') {
            return;
        }

        try {
            $payload = $event->payload ?? [];
            $normalized = $normalizer->normalize($payload);
            $instanceName = $normalized['instance']
                ?? data_get($payload, 'instance')
                ?? data_get($payload, 'instanceName');

            $instance = null;
            if (is_string($instanceName) && $instanceName !== '') {
                $instance = WhatsappInstance::query()
                    ->where('evolution_instance', $instanceName)
                    ->first();
            }

            if (! $instance && $event->tenant_id) {
                $instance = WhatsappInstance::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->latest('id')
                    ->first();
            }

            if (! $instance) {
                $event->update([
                    'status' => 'ignored',
                    'error_message' => 'Instancia WhatsApp no encontrada',
                    'processed_at' => now(),
                ]);

                return;
            }

            if (! $event->tenant_id) {
                $event->tenant_id = $instance->tenant_id;
            }

            if ($normalized['kind'] === 'connection') {
                $state = strtolower((string) data_get($normalized, 'connection.state', ''));
                $mapped = match (true) {
                    in_array($state, ['open', 'connected'], true) => 'open',
                    in_array($state, ['connecting', 'close', 'closed', 'disconnected'], true) => $state === 'connecting' ? 'connecting' : 'disconnected',
                    default => $instance->status,
                };
                $instance->update([
                    'status' => $mapped,
                    'last_connected_at' => $mapped === 'open' ? now() : $instance->last_connected_at,
                ]);
                $event->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }

            if ($normalized['kind'] === 'qrcode') {
                $meta = $instance->meta ?? [];
                $meta['last_qr'] = [
                    'base64' => data_get($normalized, 'qrcode.base64'),
                    'updated_at' => now()->toIso8601String(),
                ];
                $instance->update([
                    'status' => 'connecting',
                    'meta' => $meta,
                ]);
                $event->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }

            foreach ($normalized['messages'] ?? [] as $message) {
                $orchestrator->handleIncoming($instance, $message);
            }

            $event->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $e) {
            Log::error('ProcessEvolutionWebhook failed', [
                'webhook_event_id' => $this->webhookEventId,
                'error' => $e->getMessage(),
            ]);
            $event->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
            throw $e;
        }
    }
}
