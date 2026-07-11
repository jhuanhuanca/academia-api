<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WhatsAppProvisioner
{
    public function __construct(private readonly EvolutionClient $evolution)
    {
    }

    /**
     * Asegura instancia en Evolution + webhook hacia MarketLuna.
     *
     * @return array{instance: WhatsappInstance, evolution: array<string, mixed>, qr?: array<string, mixed>|null}
     */
    public function ensureAndConnect(WhatsappInstance $instance): array
    {
        $name = $instance->evolution_instance;
        $webhookUrl = (string) config('services.evolution.webhook_url');

        $created = null;
        try {
            $created = $this->evolution->createInstance([
                'instanceName' => $name,
                'integration' => $instance->integration === 'business' ? 'WHATSAPP-BUSINESS' : 'WHATSAPP-BAILEYS',
                'qrcode' => true,
                'token' => $instance->evolution_apikey ?: Str::random(32),
                'webhook' => $this->webhookConfig($webhookUrl),
            ]);

            if (! empty($created['hash']) || ! empty($created['token'])) {
                $instance->evolution_apikey = (string) ($created['hash'] ?? $created['token']);
            }
        } catch (Throwable $e) {
            // Si ya existe, continuamos a conectar
            Log::info('Evolution createInstance note', ['error' => $e->getMessage()]);
            try {
                $this->evolution->setWebhook($name, $this->webhookConfig($webhookUrl));
            } catch (Throwable $webhookError) {
                Log::warning('setWebhook failed', ['error' => $webhookError->getMessage()]);
            }
        }

        // Asegura webhook con base64 aunque la instancia ya existiera
        try {
            $this->evolution->setWebhook($name, $this->webhookConfig($webhookUrl));
        } catch (Throwable $e) {
            Log::info('setWebhook refresh note', ['error' => $e->getMessage()]);
        }

        $qr = null;
        try {
            $qr = $this->evolution->connect($name);
        } catch (Throwable $e) {
            Log::warning('Evolution connect failed', ['error' => $e->getMessage()]);
        }

        $state = null;
        try {
            $state = $this->evolution->connectionState($name);
        } catch (Throwable $e) {
            // ignore
        }

        $status = strtolower((string) (
            data_get($state, 'instance.state')
            ?? data_get($state, 'state')
            ?? 'connecting'
        ));

        $mapped = match (true) {
            in_array($status, ['open', 'connected'], true) => 'open',
            $status === 'connecting' => 'connecting',
            default => 'disconnected',
        };

        $meta = $instance->meta ?? [];
        $base64 = data_get($qr, 'base64')
            ?? data_get($qr, 'qrcode.base64')
            ?? data_get($created, 'qrcode.base64');

        if ($base64) {
            $meta['last_qr'] = [
                'base64' => $base64,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        $instance->forceFill([
            'status' => $mapped,
            'meta' => $meta,
            'last_connected_at' => $mapped === 'open' ? now() : $instance->last_connected_at,
        ])->save();

        return [
            'instance' => $instance->fresh(),
            'evolution' => [
                'create' => $created,
                'state' => $state,
                'connect' => $qr,
            ],
            'qr' => $base64 ? ['base64' => $base64] : null,
        ];
    }

    /**
     * @return array{instance: WhatsappInstance, state: array<string, mixed>|null}
     */
    public function refreshStatus(WhatsappInstance $instance): array
    {
        $state = null;
        try {
            $state = $this->evolution->connectionState($instance->evolution_instance);
            $status = strtolower((string) (
                data_get($state, 'instance.state')
                ?? data_get($state, 'state')
                ?? $instance->status
            ));
            $mapped = match (true) {
                in_array($status, ['open', 'connected'], true) => 'open',
                $status === 'connecting' => 'connecting',
                default => 'disconnected',
            };
            $instance->update([
                'status' => $mapped,
                'last_connected_at' => $mapped === 'open' ? now() : $instance->last_connected_at,
                'phone_e164' => data_get($state, 'instance.owner')
                    ?? data_get($state, 'instance.wuid')
                    ?? $instance->phone_e164,
            ]);
        } catch (Throwable $e) {
            Log::warning('refreshStatus failed', ['error' => $e->getMessage()]);
        }

        return [
            'instance' => $instance->fresh(),
            'state' => $state,
        ];
    }

    /**
     * Config webhook compatible con Evolution v1/v2 (nombres de claves distintos).
     *
     * @return array<string, mixed>
     */
    private function webhookConfig(string $webhookUrl): array
    {
        $events = [
            'MESSAGES_UPSERT',
            'CONNECTION_UPDATE',
            'QRCODE_UPDATED',
            'SEND_MESSAGE',
        ];

        return [
            'enabled' => true,
            'url' => $webhookUrl,
            // v2
            'webhookByEvents' => false,
            'webhookBase64' => true,
            // alias v1 / algunas builds Docker
            'byEvents' => false,
            'base64' => true,
            'events' => $events,
        ];
    }
}
