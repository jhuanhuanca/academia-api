<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappInstance;
use App\Services\WhatsApp\Contracts\WhatsAppMessenger;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaCloudMessenger implements WhatsAppMessenger
{
    public function __construct(private readonly MetaCloudClient $client)
    {
    }

    public function sendText(WhatsappInstance $instance, string $number, string $text): array
    {
        return $this->client->sendText($instance, $number, $text);
    }

    public function sendButtons(
        WhatsappInstance $instance,
        string $number,
        string $title,
        string $description,
        array $buttons,
        ?string $footer = null
    ): array {
        $body = trim($title);
        if (trim($description) !== '' && trim($description) !== $body) {
            $body = $body !== '' ? $body."\n\n".trim($description) : trim($description);
        }
        if ($body === '') {
            $body = 'Elige una opción';
        }

        return $this->client->sendReplyButtons($instance, $number, $body, $buttons, $footer);
    }

    public function sendMediaBinary(
        WhatsappInstance $instance,
        string $number,
        string $binary,
        string $fileName,
        string $mimetype,
        string $mediatype = 'image',
        ?string $caption = null
    ): array {
        return $this->client->sendMediaBinary(
            $instance,
            $number,
            $binary,
            $fileName,
            $mimetype,
            $mediatype,
            $caption
        );
    }

    public function downloadInboundMedia(WhatsappInstance $instance, array $incoming): ?array
    {
        $mediaId = data_get($incoming, 'meta_media_id')
            ?? data_get($incoming, 'raw.meta_media_id')
            ?? data_get($incoming, 'raw.image.id')
            ?? data_get($incoming, 'raw.video.id')
            ?? data_get($incoming, 'raw.document.id')
            ?? data_get($incoming, 'raw.audio.id');

        if (! is_string($mediaId) || $mediaId === '') {
            return null;
        }

        try {
            $file = $this->client->downloadMedia($instance, $mediaId);

            return [
                'base64' => 'data:'.$file['mime'].';base64,'.base64_encode($file['binary']),
                'mime' => $file['mime'],
            ];
        } catch (Throwable $e) {
            Log::warning('Meta Cloud downloadInboundMedia falló', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function supportsNativeButtons(): bool
    {
        return true;
    }
}
