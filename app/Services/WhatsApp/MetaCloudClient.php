<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente Graph API — WhatsApp Cloud API oficial.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class MetaCloudClient
{
    /**
     * @return array<string, mixed>
     */
    public function sendText(WhatsappInstance $instance, string $to, string $text): array
    {
        return $this->sendMessage($instance, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizeNumber($to),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]);
    }

    /**
     * Botones interactivos (máx. 3).
     *
     * @param  list<array{id?:string,label?:string,displayText?:string,display?:string}>  $buttons
     * @return array<string, mixed>
     */
    public function sendReplyButtons(
        WhatsappInstance $instance,
        string $to,
        string $body,
        array $buttons,
        ?string $footer = null
    ): array {
        $mapped = [];
        foreach (array_slice($buttons, 0, 3) as $button) {
            $id = (string) ($button['id'] ?? uniqid('btn_', true));
            $label = (string) ($button['label'] ?? $button['displayText'] ?? $button['display'] ?? 'Opción');
            if (mb_strlen($label) > 20) {
                $label = mb_substr($label, 0, 20);
            }
            $mapped[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => $id,
                    'title' => $label,
                ],
            ];
        }

        $interactive = [
            'type' => 'button',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => $mapped],
        ];
        if (is_string($footer) && trim($footer) !== '') {
            $interactive['footer'] = ['text' => mb_substr(trim($footer), 0, 60)];
        }

        return $this->sendMessage($instance, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizeNumber($to),
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Sube binario a Graph y envía como image/video/document/audio.
     *
     * @return array<string, mixed>
     */
    public function sendMediaBinary(
        WhatsappInstance $instance,
        string $to,
        string $binary,
        string $fileName,
        string $mimetype,
        string $mediatype = 'image',
        ?string $caption = null
    ): array {
        $mediaId = $this->uploadMedia($instance, $binary, $fileName, $mimetype);
        $type = in_array($mediatype, ['image', 'video', 'document', 'audio'], true)
            ? $mediatype
            : 'image';

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizeNumber($to),
            'type' => $type,
            $type => array_filter([
                'id' => $mediaId,
                'caption' => $caption !== null && $caption !== '' ? mb_substr($caption, 0, 1024) : null,
                'filename' => $type === 'document' ? $fileName : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        return $this->sendMessage($instance, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(WhatsappInstance $instance, array $payload): array
    {
        $phoneNumberId = $this->phoneNumberId($instance);
        $response = $this->http($instance)
            ->post($this->graphUrl('/'.$phoneNumberId.'/messages'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Meta Cloud send failed HTTP '.$response->status().': '.$response->body()
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $json['messageId'] = data_get($json, 'messages.0.id');

        return $json;
    }

    public function uploadMedia(
        WhatsappInstance $instance,
        string $binary,
        string $fileName,
        string $mimetype
    ): string {
        $phoneNumberId = $this->phoneNumberId($instance);
        $response = Http::withToken($this->accessToken($instance))
            ->acceptJson()
            ->timeout((int) config('services.meta_whatsapp.timeout', 60))
            ->attach('file', $binary, $fileName)
            ->post($this->graphUrl('/'.$phoneNumberId.'/media'), [
                'messaging_product' => 'whatsapp',
                'type' => $mimetype,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Meta Cloud media upload failed HTTP '.$response->status().': '.$response->body()
            );
        }

        $id = (string) data_get($response->json(), 'id', '');
        if ($id === '') {
            throw new RuntimeException('Meta Cloud media upload sin id');
        }

        return $id;
    }

    /**
     * @return array{binary:string,mime:string}
     */
    public function downloadMedia(WhatsappInstance $instance, string $mediaId): array
    {
        $meta = $this->http($instance)->get($this->graphUrl('/'.$mediaId));
        if (! $meta->successful()) {
            throw new RuntimeException(
                'Meta Cloud media meta failed HTTP '.$meta->status().': '.$meta->body()
            );
        }

        $url = (string) data_get($meta->json(), 'url', '');
        $mime = (string) (data_get($meta->json(), 'mime_type') ?: 'application/octet-stream');
        if ($url === '') {
            throw new RuntimeException('Meta Cloud media sin URL');
        }

        $file = Http::withToken($this->accessToken($instance))
            ->timeout((int) config('services.meta_whatsapp.timeout', 60))
            ->get($url);

        if (! $file->successful()) {
            throw new RuntimeException(
                'Meta Cloud media download failed HTTP '.$file->status()
            );
        }

        return [
            'binary' => $file->body(),
            'mime' => $mime,
        ];
    }

    /**
     * Health check liviano: GET phone number.
     *
     * @return array{status:string,http_status?:int,error?:string,body?:mixed}
     */
    public function health(?WhatsappInstance $instance = null): array
    {
        try {
            if (! $instance) {
                $token = (string) config('services.meta_whatsapp.access_token', '');
                $phoneId = (string) config('services.meta_whatsapp.phone_number_id', '');
                if ($token === '' || $phoneId === '') {
                    return ['status' => 'unconfigured'];
                }
                $response = Http::withToken($token)
                    ->timeout(15)
                    ->get($this->graphUrl('/'.$phoneId), ['fields' => 'id,display_phone_number,verified_name']);
            } else {
                $response = $this->http($instance)
                    ->get($this->graphUrl('/'.$this->phoneNumberId($instance)), [
                        'fields' => 'id,display_phone_number,verified_name',
                    ]);
            }

            return [
                'status' => $response->successful() ? 'ok' : 'down',
                'http_status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function http(WhatsappInstance $instance): PendingRequest
    {
        return Http::withToken($this->accessToken($instance))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.meta_whatsapp.timeout', 60));
    }

    private function graphUrl(string $path): string
    {
        $version = trim((string) config('services.meta_whatsapp.graph_version', 'v21.0'), '/');
        $base = rtrim((string) config('services.meta_whatsapp.graph_base', 'https://graph.facebook.com'), '/');

        return $base.'/'.$version.$path;
    }

    private function phoneNumberId(WhatsappInstance $instance): string
    {
        $id = trim((string) (
            $instance->meta_phone_number_id
            ?: config('services.meta_whatsapp.phone_number_id')
        ));
        if ($id === '') {
            throw new RuntimeException('Falta meta_phone_number_id (instancia o META_WA_PHONE_NUMBER_ID).');
        }

        return $id;
    }

    private function accessToken(WhatsappInstance $instance): string
    {
        $token = trim((string) (
            $instance->meta_access_token
            ?: config('services.meta_whatsapp.access_token')
        ));
        if ($token === '') {
            throw new RuntimeException('Falta meta_access_token (instancia o META_WA_ACCESS_TOKEN).');
        }

        return $token;
    }

    private function normalizeNumber(string $number): string
    {
        // Cloud API espera dígitos internacionales sin +
        if (str_contains($number, '@')) {
            $number = explode('@', $number, 2)[0];
        }

        return preg_replace('/\D+/', '', $number) ?? '';
    }
}
