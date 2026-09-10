<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once a user's account is finalised — the transactional version of the
 * comms/welcome-email template.
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to Spi, '.$this->firstName());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.welcome',
            with: [
                'firstName' => $this->firstName(),
                'testerUrl' => rtrim(config('app.url'), '/').'/tester',
                'docsUrl' => rtrim(config('app.url'), '/').'/docs/quickstart',
            ],
        );
    }

    private function firstName(): string
    {
        return trim(explode(' ', (string) $this->user->name)[0]) ?: 'there';
    }
}
