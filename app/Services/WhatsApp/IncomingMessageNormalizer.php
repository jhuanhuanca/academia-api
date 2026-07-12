<?php

namespace App\Services\WhatsApp;

/**
 * Normaliza payloads de Evolution (MESSAGES_UPSERT, CONNECTION_UPDATE, QRCODE_UPDATED).
 */
class IncomingMessageNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   event: string,
     *   instance: string|null,
     *   kind: string,
     *   messages?: list<array<string, mixed>>,
     *   connection?: array<string, mixed>,
     *   qrcode?: array<string, mixed>
     * }
     */
    public function normalize(array $payload): array
    {
        $event = strtoupper((string) ($payload['event'] ?? $payload['type'] ?? 'UNKNOWN'));
        $instance = $payload['instance'] ?? $payload['instanceName'] ?? data_get($payload, 'data.instanceName');

        if (str_contains($event, 'CONNECTION_UPDATE') || str_contains($event, 'CONNECTION')) {
            return [
                'event' => $event,
                'instance' => $instance,
                'kind' => 'connection',
                'connection' => [
                    'state' => data_get($payload, 'data.state')
                        ?? data_get($payload, 'data.status')
                        ?? data_get($payload, 'state'),
                    'raw' => $payload['data'] ?? $payload,
                ],
            ];
        }

        if (str_contains($event, 'QRCODE')) {
            return [
                'event' => $event,
                'instance' => $instance,
                'kind' => 'qrcode',
                'qrcode' => [
                    'base64' => data_get($payload, 'data.base64')
                        ?? data_get($payload, 'data.qrcode.base64')
                        ?? data_get($payload, 'qrcode.base64'),
                    'code' => data_get($payload, 'data.code')
                        ?? data_get($payload, 'data.qrcode.code'),
                    'raw' => $payload['data'] ?? $payload,
                ],
            ];
        }

        $messages = $this->extractMessages($payload);

        return [
            'event' => $event,
            'instance' => is_string($instance) ? $instance : null,
            'kind' => 'messages',
            'messages' => $messages,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{
     *   wa_message_id: string|null,
     *   phone_e164: string|null,
     *   wa_name: string|null,
     *   from_me: bool,
     *   type: string,
     *   body: string|null,
     *   button_id: string|null,
     *   list_id: string|null,
     *   raw: array
     * }>
     */
    private function extractMessages(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        $items = [];

        if (isset($data['key']) || isset($data['message'])) {
            $items = [$data];
        } elseif (isset($data[0]) && is_array($data[0])) {
            $items = $data;
        } elseif (isset($data['messages']) && is_array($data['messages'])) {
            $items = $data['messages'];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = $this->mapMessage($item);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapMessage(array $item): array
    {
        $key = is_array($item['key'] ?? null) ? $item['key'] : [];
        $remoteJid = (string) ($key['remoteJid'] ?? $item['remoteJid'] ?? '');
        $remoteJidAlt = (string) ($key['remoteJidAlt'] ?? $item['remoteJidAlt'] ?? '');
        $senderPn = (string) ($key['senderPn'] ?? $item['senderPn'] ?? '');
        $fromMe = (bool) ($key['fromMe'] ?? false);
        $messageId = $key['id'] ?? $item['id'] ?? null;
        $pushName = $item['pushName'] ?? data_get($item, 'message.pushName');

        $resolved = $this->resolveAddress($remoteJid, $remoteJidAlt, $senderPn, $item);

        $message = $item['message'] ?? [];

        // Desenvuelve viewOnce / ephemeral (comprobantes a veces vienen así)
        foreach (['viewOnceMessage', 'viewOnceMessageV2', 'ephemeralMessage', 'documentWithCaptionMessage'] as $wrap) {
            $inner = data_get($message, $wrap.'.message');
            if (is_array($inner)) {
                $message = $inner;
                break;
            }
        }

        $type = 'text';
        $body = null;
        $buttonId = null;
        $listId = null;

        if (isset($message['conversation'])) {
            $body = (string) $message['conversation'];
        } elseif (isset($message['extendedTextMessage']['text'])) {
            $body = (string) $message['extendedTextMessage']['text'];
        } elseif (isset($message['buttonsResponseMessage'])) {
            $type = 'button_reply';
            $buttonId = (string) (
                $message['buttonsResponseMessage']['selectedButtonId']
                ?? $message['buttonsResponseMessage']['selectedId']
                ?? ''
            );
            $body = (string) ($message['buttonsResponseMessage']['selectedDisplayText'] ?? $buttonId);
        } elseif (isset($message['listResponseMessage'])) {
            $type = 'list_reply';
            $listId = (string) data_get($message, 'listResponseMessage.singleSelectReply.selectedRowId', '');
            $body = (string) data_get($message, 'listResponseMessage.title', $listId);
        } elseif (isset($message['templateButtonReplyMessage'])) {
            $type = 'button_reply';
            $buttonId = (string) (
                $message['templateButtonReplyMessage']['selectedId']
                ?? $message['templateButtonReplyMessage']['selectedIndex']
                ?? ''
            );
            $body = (string) ($message['templateButtonReplyMessage']['selectedDisplayText'] ?? $buttonId);
        } elseif (isset($message['interactiveResponseMessage'])) {
            $type = 'button_reply';
            $buttonId = (string) data_get(
                $message,
                'interactiveResponseMessage.nativeFlowResponseMessage.paramsJson',
                ''
            );
            $body = $buttonId;
        } elseif (isset($message['imageMessage'])) {
            $type = 'image';
            $body = (string) ($message['imageMessage']['caption'] ?? '[imagen]');
        } elseif (isset($message['audioMessage'])) {
            $type = 'audio';
            $body = '[audio]';
        } elseif (isset($message['documentMessage'])) {
            $type = 'document';
            $body = (string) ($message['documentMessage']['fileName'] ?? '[documento]');
        } elseif (isset($message['stickerMessage'])) {
            $type = 'image';
            $body = '[sticker]';
        }

        // Algunos payloads Evolution v2 traen messageType + text fuera
        $messageType = (string) ($item['messageType'] ?? '');
        if ($body === null && $messageType !== '') {
            if (str_contains(strtolower($messageType), 'image')) {
                $type = 'image';
                $body = '[imagen]';
            } elseif (str_contains(strtolower($messageType), 'document')) {
                $type = 'document';
                $body = '[documento]';
            } else {
                $type = $messageType;
                $body = data_get($item, 'message.conversation')
                    ?? data_get($item, 'text')
                    ?? null;
            }
        }

        // Reinyecta message desenvuelto en raw para descarga de media
        $itemForRaw = $item;
        $itemForRaw['message'] = $message;

        return [
            'wa_message_id' => is_string($messageId) ? $messageId : null,
            'phone_e164' => $resolved['phone_e164'],
            // Destino para Evolution: teléfono real o JID @lid completo
            'reply_to' => $resolved['reply_to'],
            'wa_name' => is_string($pushName) ? $pushName : null,
            'from_me' => $fromMe,
            'type' => $type,
            'body' => $body,
            'button_id' => $buttonId !== '' ? $buttonId : null,
            'list_id' => $listId !== '' ? $listId : null,
            'raw' => $itemForRaw,
        ];
    }

    /**
     * WhatsApp ahora envía a menudo remoteJid=@lid (ID interno).
     * El teléfono real suele venir en remoteJidAlt / senderPn.
     * Si solo hay @lid, hay que responder a "digits@lid" o Evolution responde exists:false.
     *
     * @return array{phone_e164: string|null, reply_to: string|null}
     */
    private function resolveAddress(string $remoteJid, string $remoteJidAlt, string $senderPn, array $item): array
    {
        $candidates = array_values(array_filter([
            $remoteJidAlt,
            $senderPn,
            (string) data_get($item, 'senderPn', ''),
            (string) ($item['key']['participant'] ?? ''),
            (string) ($item['participant'] ?? ''),
            $remoteJid,
        ], fn ($v) => is_string($v) && trim($v) !== ''));

        $phone = null;
        $lidJid = null;

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            // Solo dígitos (senderPn a veces viene así)
            if (! str_contains($candidate, '@')) {
                $digits = preg_replace('/\D+/', '', $candidate) ?? '';
                if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                    $phone = $phone ?? '+'.$digits;
                }
                continue;
            }

            if (str_contains($candidate, '@g.us') || str_contains($candidate, '@broadcast')) {
                continue;
            }

            if (str_contains($candidate, '@lid')) {
                $lidJid = $lidJid ?? $candidate;
                continue;
            }

            // @s.whatsapp.net u otros JID con teléfono real
            $asPhone = $this->jidToPhone($candidate);
            if ($asPhone) {
                $phone = $phone ?? $asPhone;
            }
        }

        if ($phone) {
            return [
                'phone_e164' => $phone,
                'reply_to' => ltrim($phone, '+'),
            ];
        }

        if ($lidJid) {
            $digits = preg_replace('/\D+/', '', explode('@', $lidJid)[0] ?? '') ?? '';

            return [
                'phone_e164' => $digits !== '' ? '+'.$digits : null,
                'reply_to' => $lidJid,
            ];
        }

        return [
            'phone_e164' => null,
            'reply_to' => null,
        ];
    }

    private function jidToPhone(string $jid): ?string
    {
        if ($jid === '') {
            return null;
        }

        // 58412...@s.whatsapp.net  |  1203...@g.us (grupo: ignoramos)
        if (str_contains($jid, '@g.us') || str_contains($jid, '@lid')) {
            return null;
        }

        $user = explode('@', $jid)[0] ?? '';
        $user = explode(':', $user)[0] ?? '';
        $digits = preg_replace('/\D+/', '', $user) ?? '';

        return $digits !== '' ? '+'.$digits : null;
    }
}
