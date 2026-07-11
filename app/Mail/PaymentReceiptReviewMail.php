<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PaymentReceiptReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public User $user,
        public string $confirmUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        $course = $this->payment->sale?->course?->title ?? 'curso';

        return new Envelope(
            subject: 'Comprobante de pago — '.$course,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-receipt-review',
            with: [
                'user' => $this->user,
                'payment' => $this->payment,
                'sale' => $this->payment->sale,
                'lead' => $this->payment->sale?->lead,
                'course' => $this->payment->sale?->course,
                'confirmUrl' => $this->confirmUrl,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $media = $this->payment->receiptMedia;
        if (! $media) {
            return [];
        }

        try {
            $disk = $media->disk ?: 'public';
            $path = $media->path;

            if (! $path || $path === 'pending' || ! Storage::disk($disk)->exists($path)) {
                // Fallback típico en VPS: receipts/{tenant}/{id}.*
                foreach (['jpg', 'jpeg', 'png', 'webp', 'pdf'] as $ext) {
                    $guess = sprintf('receipts/%d/%d.%s', $media->tenant_id, $media->id, $ext);
                    if (Storage::disk('public')->exists($guess)) {
                        $disk = 'public';
                        $path = $guess;
                        break;
                    }
                }
            }

            if (! $path || ! Storage::disk($disk)->exists($path)) {
                Log::warning('Email sin adjunto: archivo de comprobante no encontrado', [
                    'payment_id' => $this->payment->id,
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $media->path,
                ]);

                return [];
            }

            return [
                Attachment::fromStorageDisk($disk, $path)
                    ->as('comprobante.'.$this->extensionForMime($media->mime ?: 'image/jpeg'))
                    ->withMime($media->mime ?: 'image/jpeg'),
            ];
        } catch (Throwable $e) {
            // El correo debe salir aunque el adjunto falle
            Log::warning('No se pudo adjuntar comprobante al email', [
                'payment_id' => $this->payment->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };
    }
}
