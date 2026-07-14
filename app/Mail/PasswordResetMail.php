<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly string $userName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recuperar contraseña — LunaMarket',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.password-reset',
        );
    }
}
