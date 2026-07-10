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
use Illuminate\Support\Facades\Storage;

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
        if (! $media || ! Storage::disk($media->disk)->exists($media->path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($media->disk, $media->path)
                ->as('comprobante.'.$this->extensionForMime($media->mime))
                ->withMime($media->mime),
        ];
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
