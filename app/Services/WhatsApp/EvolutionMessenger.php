<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappInstance;
use App\Services\WhatsApp\Contracts\WhatsAppMessenger;
use RuntimeException;

class EvolutionMessenger implements WhatsAppMessenger
{
    public function __construct(private readonly EvolutionClient $evolution)
    {
    }

    public function sendText(WhatsappInstance $instance, string $number, string $text): array
    {
        return $this->evolution->sendText($this->name($instance), $number, $text);
    }

    public function sendButtons(
        WhatsappInstance $instance,
        string $number,
        string $title,
        string $description,
        array $buttons,
        ?string $footer = null
    ): array {
        return $this->evolution->sendButtons(
            $this->name($instance),
            $number,
            $title,
            $description,
            $buttons,
            $footer
        );
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
        return $this->evolution->sendMediaFile(
            $this->name($instance),
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
        // Evolution sigue usando getBase64FromMediaMessage vía WhatsAppMediaService.
        return null;
    }

    public function supportsNativeButtons(): bool
    {
        return false;
    }

    private function name(WhatsappInstance $instance): string
    {
        $name = trim((string) $instance->evolution_instance);
        if ($name === '') {
            throw new RuntimeException('Instancia Evolution sin evolution_instance.');
        }

        return $name;
    }
}
