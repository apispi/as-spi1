<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $setupUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Finish setting up your Spi account');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.registration-verification',
            with: ['url' => $this->setupUrl],
        );
    }
}
