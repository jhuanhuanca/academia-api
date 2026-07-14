<?php

namespace App\Services\WhatsApp;

/**
 * Normaliza webhooks de WhatsApp Cloud API al mismo shape que IncomingMessageNormalizer.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples
 */
class MetaCloudIncomingNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function normalize(array $payload): array
    {
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            $changes = data_get($entry, 'changes', []);
            if (! is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                if (data_get($change, 'field') !== 'messages') {
                    continue;
                }
                $value = data_get($change, 'value', []);
                if (! is_array($value)) {
                    continue;
                }

                $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');
                $displayPhone = (string) data_get($value, 'metadata.display_phone_number', '');
                $contacts = data_get($value, 'contacts', []);
                $contactName = is_array($contacts)
                    ? (string) data_get($contacts, '0.profile.name', '')
                    : '';

                $messages = data_get($value, 'messages', []);
                if (! is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    $normalized = $this->mapMessage($message, $phoneNumberId, $displayPhone, $contactName);
                    if ($normalized !== null) {
                        $out[] = $normalized;
                    }
                }

                // statuses (delivered/read) — ignoramos por ahora
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function mapMessage(
        array $message,
        string $phoneNumberId,
        string $displayPhone,
        string $contactName
    ): ?array {
        $from = (string) ($message['from'] ?? '');
        if ($from === '') {
            return null;
        }

        $phone = '+'.ltrim(preg_replace('/\D+/', '', $from) ?? '', '+');
        $type = (string) ($message['type'] ?? 'text');
        $waId = (string) ($message['id'] ?? '');

        $body = '';
        $buttonId = null;
        $listId = null;
        $metaMediaId = null;
        $mappedType = 'text';

        switch ($type) {
            case 'text':
                $body = (string) data_get($message, 'text.body', '');
                $mappedType = 'text';
                break;
            case 'button':
                $body = (string) data_get($message, 'button.text', '');
                $buttonId = (string) data_get($message, 'button.payload', $body);
                $mappedType = 'button_reply';
                break;
            case 'interactive':
                $interactiveType = (string) data_get($message, 'interactive.type', '');
                if ($interactiveType === 'button_reply') {
                    $buttonId = (string) data_get($message, 'interactive.button_reply.id', '');
                    $body = (string) data_get($message, 'interactive.button_reply.title', $buttonId);
                    $mappedType = 'button_reply';
                } elseif ($interactiveType === 'list_reply') {
                    $listId = (string) data_get($message, 'interactive.list_reply.id', '');
                    $body = (string) data_get($message, 'interactive.list_reply.title', $listId);
                    $buttonId = $listId;
                    $mappedType = 'button_reply';
                } else {
                    $body = '[interactive]';
                }
                break;
            case 'image':
                $metaMediaId = (string) data_get($message, 'image.id', '');
                $body = (string) data_get($message, 'image.caption', '[imagen]');
                $mappedType = 'image';
                break;
            case 'document':
                $metaMediaId = (string) data_get($message, 'document.id', '');
                $body = (string) data_get($message, 'document.caption', data_get($message, 'document.filename', '[documento]'));
                $mappedType = 'document';
                break;
            case 'video':
                $metaMediaId = (string) data_get($message, 'video.id', '');
                $body = (string) data_get($message, 'video.caption', '[video]');
                $mappedType = 'image'; // pipeline de comprobante trata image/document
                break;
            case 'audio':
                $metaMediaId = (string) data_get($message, 'audio.id', '');
                $body = '[audio]';
                $mappedType = 'text';
                break;
            default:
                $body = '['.$type.']';
                $mappedType = 'text';
        }

        return [
            'kind' => 'messages',
            'wa_message_id' => $waId,
            'phone_e164' => $phone,
            'reply_to' => $from,
            'wa_name' => $contactName !== '' ? $contactName : null,
            'from_me' => false,
            'type' => $mappedType,
            'body' => $body,
            'button_id' => $buttonId,
            'list_id' => $listId,
            'meta_media_id' => $metaMediaId !== '' ? $metaMediaId : null,
            'meta_phone_number_id' => $phoneNumberId,
            'raw' => array_filter([
                'provider' => 'meta_cloud',
                'meta_media_id' => $metaMediaId !== '' ? $metaMediaId : null,
                'display_phone_number' => $displayPhone !== '' ? $displayPhone : null,
                'message' => $message,
                $type => $message[$type] ?? null,
            ]),
        ];
    }
}
