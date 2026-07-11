<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Envío por API HTTPS de Brevo (puerto 443).
 * Evita bloqueos de SMTP (587/465) en VPS como DigitalOcean.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $from = $email->getFrom()[0] ?? null;
        if (! $from instanceof Address) {
            throw new RuntimeException('Brevo: falta remitente (MAIL_FROM_ADDRESS)');
        }

        $to = [];
        foreach ($email->getTo() as $address) {
            $to[] = array_filter([
                'email' => $address->getAddress(),
                'name' => $address->getName() ?: null,
            ]);
        }

        if ($to === []) {
            throw new RuntimeException('Brevo: no hay destinatarios');
        }

        $payload = [
            'sender' => array_filter([
                'email' => $from->getAddress(),
                'name' => $from->getName() ?: null,
            ]),
            'to' => $to,
            'subject' => $email->getSubject() ?: '(sin asunto)',
        ];

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $payload['htmlContent'] = $html;
        }
        if (is_string($text) && $text !== '') {
            $payload['textContent'] = $text;
        }

        if (empty($payload['htmlContent']) && empty($payload['textContent'])) {
            $payload['textContent'] = '(mensaje vacío)';
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $name = method_exists($attachment, 'getFilename')
                ? $attachment->getFilename()
                : null;
            if (! is_string($name) || $name === '') {
                $name = $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename')
                    ?: 'adjunto.bin';
            }

            $body = method_exists($attachment, 'bodyToString')
                ? $attachment->bodyToString()
                : (string) $attachment->getBody();

            $attachments[] = [
                'name' => $name,
                'content' => base64_encode($body),
            ];
        }
        if ($attachments !== []) {
            $payload['attachment'] = $attachments;
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'api-key' => $this->apiKey,
                'accept' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Brevo API '.$response->status().': '.$response->body()
            );
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
