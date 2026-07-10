<?php

namespace App\Services\WhatsApp;

use App\Models\MediaAsset;
use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class WhatsAppMediaService
{
    public function __construct(private readonly EvolutionClient $evolution)
    {
    }

    /**
     * Guarda comprobante desde mensaje entrante (Evolution o simulación).
     *
     * @param  array<string, mixed>  $incoming
     */
    public function storeReceiptFromIncoming(WhatsappInstance $instance, array $incoming): ?MediaAsset
    {
        $simulated = data_get($incoming, 'raw.receipt_base64')
            ?? data_get($incoming, 'receipt_base64');

        if (is_string($simulated) && $simulated !== '') {
            return $this->storeFromBase64($instance->tenant_id, $simulated, 'receipt-sim');
        }

        $raw = $incoming['raw'] ?? [];
        if (! is_array($raw)) {
            return null;
        }

        try {
            $response = $this->evolution->getBase64FromMediaMessage(
                $instance->evolution_instance,
                $this->buildMediaPayload($raw)
            );

            $base64 = data_get($response, 'base64')
                ?? data_get($response, 'data.base64')
                ?? data_get($response, 'media.base64');

            if (! is_string($base64) || $base64 === '') {
                return null;
            }

            $mime = (string) (
                data_get($response, 'mimetype')
                ?? data_get($response, 'data.mimetype')
                ?? 'image/jpeg'
            );

            return $this->storeFromBase64($instance->tenant_id, $base64, 'receipt-wa', $mime);
        } catch (Throwable $e) {
            Log::warning('No se pudo descargar media de Evolution', [
                'error' => $e->getMessage(),
                'instance' => $instance->evolution_instance,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function buildMediaPayload(array $raw): array
    {
        $message = data_get($raw, 'message') ?? data_get($raw, 'data.message') ?? $raw;
        $key = data_get($raw, 'key') ?? data_get($raw, 'data.key') ?? data_get($message, 'key');

        return array_filter([
            'message' => is_array($message) ? $message : ['key' => $key],
            'convertToMp4' => false,
        ]);
    }

    private function storeFromBase64(
        int $tenantId,
        string $base64,
        string $prefix,
        ?string $mime = null
    ): MediaAsset {
        if (str_contains($base64, 'base64,')) {
            [$meta, $base64] = explode('base64,', $base64, 2);
            if (! $mime && preg_match('/data:([^;]+);/', $meta, $m)) {
                $mime = $m[1];
            }
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new \RuntimeException('Base64 de comprobante inválido');
        }

        $mime = $mime ?: 'image/jpeg';
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };

        $path = sprintf(
            'receipts/%d/%s_%s.%s',
            $tenantId,
            $prefix,
            Str::uuid(),
            $ext
        );

        Storage::disk('local')->put($path, $binary);

        return MediaAsset::create([
            'tenant_id' => $tenantId,
            'disk' => 'local',
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => strlen($binary),
            'checksum' => hash('sha256', $binary),
        ]);
    }
}
