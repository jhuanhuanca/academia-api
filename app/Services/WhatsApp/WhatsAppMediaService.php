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

        $inline = $this->extractInlineBase64($raw);
        if ($inline) {
            try {
                return $this->storeFromBase64(
                    $instance->tenant_id,
                    $inline['base64'],
                    'receipt-wa',
                    $inline['mime']
                );
            } catch (Throwable $e) {
                Log::warning('Inline base64 de comprobante inválido', ['error' => $e->getMessage()]);
            }
        }

        try {
            $payloads = $this->buildMediaPayloadVariants($raw);
            $lastError = null;

            foreach ($payloads as $payload) {
                if (empty(data_get($payload, 'message.key.id'))) {
                    continue;
                }

                try {
                    $response = $this->evolution->getBase64FromMediaMessage(
                        $instance->evolution_instance,
                        $payload
                    );

                    $base64 = data_get($response, 'base64')
                        ?? data_get($response, 'data.base64')
                        ?? data_get($response, 'media.base64');

                    if (! is_string($base64) || $base64 === '') {
                        continue;
                    }

                    $mime = (string) (
                        data_get($response, 'mimetype')
                        ?? data_get($response, 'data.mimetype')
                        ?? data_get($raw, 'message.imageMessage.mimetype')
                        ?? data_get($raw, 'message.documentMessage.mimetype')
                        ?? 'image/jpeg'
                    );

                    return $this->storeFromBase64($instance->tenant_id, $base64, 'receipt-wa', $mime);
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }

            Log::warning('No se pudo descargar media de Evolution', [
                'error' => $lastError,
                'instance' => $instance->evolution_instance,
                'has_key' => (bool) data_get($raw, 'key.id'),
                'message_keys' => array_keys((array) data_get($raw, 'message', [])),
            ]);
        } catch (Throwable $e) {
            Log::warning('Error general al procesar comprobante', [
                'error' => $e->getMessage(),
                'instance' => $instance->evolution_instance,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{base64:string,mime:?string}|null
     */
    private function extractInlineBase64(array $raw): ?array
    {
        $candidates = [
            data_get($raw, 'message.base64'),
            data_get($raw, 'base64'),
            data_get($raw, 'data.base64'),
            data_get($raw, 'msgContent'),
            data_get($raw, 'message.imageMessage.base64'),
            data_get($raw, 'message.documentMessage.base64'),
        ];

        foreach ($candidates as $value) {
            if (! is_string($value) || strlen($value) < 200) {
                continue;
            }

            $mime = null;
            if (str_contains($value, 'base64,')) {
                if (preg_match('/data:([^;]+);/', $value, $m)) {
                    $mime = $m[1];
                }
            }

            return ['base64' => $value, 'mime' => $mime];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function buildMediaPayloadVariants(array $raw): array
    {
        $key = data_get($raw, 'key') ?? data_get($raw, 'data.key') ?? [];
        if (! is_array($key)) {
            $key = [];
        }

        $id = $key['id'] ?? data_get($raw, 'id') ?? data_get($raw, 'messageId');
        if (! is_string($id) || $id === '') {
            return [];
        }

        $remoteJid = $key['remoteJid'] ?? data_get($raw, 'remoteJid');
        $fromMe = (bool) ($key['fromMe'] ?? false);
        $messageBody = data_get($raw, 'message') ?? data_get($raw, 'data.message');

        $keyFull = array_filter([
            'remoteJid' => is_string($remoteJid) ? $remoteJid : null,
            'fromMe' => $fromMe,
            'id' => $id,
            'participant' => $key['participant'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $variants = [
            [
                'message' => ['key' => $keyFull],
                'convertToMp4' => false,
            ],
            [
                'message' => ['key' => ['id' => $id]],
                'convertToMp4' => false,
            ],
        ];

        if (is_array($messageBody)) {
            $variants[] = [
                'message' => [
                    'key' => $keyFull,
                    'message' => $messageBody,
                ],
                'convertToMp4' => false,
            ];
        }

        return $variants;
    }

    public function storeFromBase64(
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
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Base64 de media inválido');
        }

        $mime = $mime ?: 'image/jpeg';
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };

        $path = sprintf('media/%d/%s_%s.%s', $tenantId, $prefix, Str::uuid(), $ext);
        Storage::disk('local')->put($path, $binary);

        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'disk' => 'local',
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => strlen($binary),
            'checksum' => hash('sha256', $binary),
        ]);

        // Espejo en disco público (para sendMedia por URL)
        $this->ensurePublicCopy($asset, $binary);

        return $asset;
    }

    public function toDataUri(MediaAsset $asset): ?string
    {
        $binary = $this->readBinary($asset);

        return $binary === null ? null : 'data:'.$asset->mime.';base64,'.base64_encode($binary);
    }

    public function toRawBase64(MediaAsset $asset): ?string
    {
        $binary = $this->readBinary($asset);

        return $binary === null ? null : base64_encode($binary);
    }

    public function publicUrl(MediaAsset $asset): ?string
    {
        $binary = $this->readBinary($asset);
        if ($binary === null) {
            return null;
        }

        $publicPath = $this->ensurePublicCopy($asset, $binary);
        if (! $publicPath) {
            return null;
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            return null;
        }

        // Si APP_URL es localhost, Evolution Docker no lo alcanza → null (usará base64)
        if (str_contains($base, '127.0.0.1') || str_contains($base, 'localhost')) {
            return null;
        }

        return $base.'/storage/'.$publicPath;
    }

    /**
     * Envía imagen a WhatsApp: multipart (preferido) → base64 → URL.
     *
     * @return array<string, mixed>
     */
    public function sendImage(
        EvolutionClient $evolution,
        string $instance,
        string $number,
        MediaAsset $asset,
        string $caption
    ): array {
        $binary = $this->readBinary($asset);
        if ($binary === null) {
            throw new \RuntimeException('Archivo QR no encontrado en disco (asset #'.$asset->id.')');
        }

        // Evolution/Baileys convierte imágenes a JPEG con sharp
        $fileName = 'qr-pago.jpg';
        $mime = 'image/jpeg';
        $errors = [];

        // 1) Multipart file — el camino más estable con Evolution Docker
        try {
            return $evolution->sendMediaFile(
                $instance,
                $number,
                $binary,
                $fileName,
                $mime,
                'image',
                $caption !== '' ? mb_substr($caption, 0, 900) : null
            );
        } catch (Throwable $e) {
            $errors[] = 'multipart: '.$e->getMessage();
            Log::warning('sendMediaFile falló', ['error' => $e->getMessage()]);
        }

        // 2) Base64 crudo (sin data URI)
        try {
            return $evolution->sendMedia(
                $instance,
                $number,
                base64_encode($binary),
                'image',
                $caption !== '' ? mb_substr($caption, 0, 900) : null,
                $fileName,
                $mime
            );
        } catch (Throwable $e) {
            $errors[] = 'raw64: '.$e->getMessage();
        }

        // 3) URL pública (si APP_URL es alcanzable desde el contenedor)
        $url = $this->publicUrl($asset);
        if ($url) {
            try {
                return $evolution->sendMedia(
                    $instance,
                    $number,
                    $url,
                    'image',
                    $caption !== '' ? mb_substr($caption, 0, 900) : null,
                    $fileName,
                    $mime
                );
            } catch (Throwable $e) {
                $errors[] = 'url: '.$e->getMessage();
            }
        }

        throw new \RuntimeException('No se pudo enviar imagen: '.implode(' | ', $errors));
    }

    private function ensurePublicCopy(MediaAsset $asset, string $binary): ?string
    {
        $ext = str_contains($asset->mime, 'png') ? 'png' : (str_contains($asset->mime, 'webp') ? 'webp' : 'jpg');
        $publicPath = sprintf('payment-qr/%d/%d.%s', $asset->tenant_id, $asset->id, $ext);

        try {
            if (! Storage::disk('public')->exists($publicPath)) {
                Storage::disk('public')->put($publicPath, $binary);
            }

            return $publicPath;
        } catch (Throwable $e) {
            Log::warning('No se pudo copiar media a public', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function readBinary(MediaAsset $asset): ?string
    {
        if (! Storage::disk($asset->disk)->exists($asset->path)) {
            return null;
        }

        $binary = Storage::disk($asset->disk)->get($asset->path);

        return ($binary === null || $binary === '') ? null : $binary;
    }
}
