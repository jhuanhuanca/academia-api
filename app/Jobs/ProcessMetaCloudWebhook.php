<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Models\WhatsappInstance;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsApp\MetaCloudIncomingNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessMetaCloudWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $webhookEventId)
    {
    }

    public function handle(
        MetaCloudIncomingNormalizer $normalizer,
        ConversationOrchestrator $orchestrator
    ): void {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if (! $event || $event->status === 'processed') {
            return;
        }

        try {
            $payload = $event->payload ?? [];
            $messages = $normalizer->normalize(is_array($payload) ? $payload : []);

            if ($messages === []) {
                $event->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => 'sin mensajes (status u otro evento)',
                ]);

                return;
            }

            foreach ($messages as $incoming) {
                $phoneNumberId = (string) ($incoming['meta_phone_number_id'] ?? '');
                $instance = null;

                if ($phoneNumberId !== '') {
                    $instance = WhatsappInstance::query()
                        ->where('integration', 'meta_cloud')
                        ->where('meta_phone_number_id', $phoneNumberId)
                        ->first();
                }

                if (! $instance && $event->tenant_id) {
                    $instance = WhatsappInstance::query()
                        ->where('tenant_id', $event->tenant_id)
                        ->where('integration', 'meta_cloud')
                        ->latest('id')
                        ->first();
                }

                if (! $instance) {
                    Log::warning('Meta webhook: instancia no encontrada', [
                        'phone_number_id' => $phoneNumberId,
                        'event_id' => $event->id,
                    ]);
                    continue;
                }

                if (! $event->tenant_id) {
                    $event->tenant_id = $instance->tenant_id;
                }

                if ($instance->status !== 'open') {
                    $instance->update([
                        'status' => 'open',
                        'last_connected_at' => now(),
                        'phone_e164' => $instance->phone_e164
                            ?: ('+'.preg_replace('/\D+/', '', (string) data_get($payload, 'entry.0.changes.0.value.metadata.display_phone_number', ''))),
                    ]);
                }

                $orchestrator->handleIncoming($instance, $incoming);
            }

            $event->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessMetaCloudWebhook failed', [
                'event_id' => $this->webhookEventId,
                'error' => $e->getMessage(),
            ]);
            $event?->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 500, ''),
                'processed_at' => now(),
            ]);
            throw $e;
        }
    }
}
