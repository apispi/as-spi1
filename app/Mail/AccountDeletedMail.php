<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation that a self-service account deletion completed. Takes plain
 * strings, never the model — by the time this sends, the account row has been
 * force-deleted, so there is nothing to serialise or reload.
 */
class AccountDeletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $firstName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Spi account has been deleted');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-deleted',
            with: [
                'firstName' => $this->firstName,
                'supportEmail' => 'support@apispi.com',
                'signupUrl' => rtrim(config('app.url'), '/').'/register',
            ],
        );
    }
}
