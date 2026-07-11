<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $applicant,
        public string $approveUrl,
        public string $rejectUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nueva solicitud de registro — '.$this->applicant->email,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.registration-approval',
            with: [
                'applicant' => $this->applicant,
                'tenant' => $this->applicant->tenant,
                'approveUrl' => $this->approveUrl,
                'rejectUrl' => $this->rejectUrl,
            ],
        );
    }
}
