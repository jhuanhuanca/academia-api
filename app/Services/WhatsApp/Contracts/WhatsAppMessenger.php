<?php

namespace App\Services\WhatsApp\Contracts;

use App\Models\WhatsappInstance;

/**
 * Contrato común Evolution/Baileys y Meta Cloud API.
 */
interface WhatsAppMessenger
{
    /**
     * @return array<string, mixed>
     */
    public function sendText(WhatsappInstance $instance, string $number, string $text): array;

    /**
     * @param  list<array{id?:string,label?:string,displayText?:string,display?:string}>  $buttons
     * @return array<string, mixed>
     */
    public function sendButtons(
        WhatsappInstance $instance,
        string $number,
        string $title,
        string $description,
        array $buttons,
        ?string $footer = null
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function sendMediaBinary(
        WhatsappInstance $instance,
        string $number,
        string $binary,
        string $fileName,
        string $mimetype,
        string $mediatype = 'image',
        ?string $caption = null
    ): array;

    /**
     * Descarga media de un inbound normalizado. null si no aplica / falla.
     *
     * @param  array<string, mixed>  $incoming
     * @return array{base64:string,mime:string}|null
     */
    public function downloadInboundMedia(WhatsappInstance $instance, array $incoming): ?array;

    public function supportsNativeButtons(): bool;
}
