<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice sent when a user changes their own password — the
 * transactional version of the comms/password-changed-email template.
 */
class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $ip = null,
        public ?string $when = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Spi password was changed');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.password-changed',
            with: [
                'firstName' => trim(explode(' ', (string) $this->user->name)[0]) ?: 'there',
                'ip' => $this->ip,
                'when' => $this->when ?? now()->toDayDateTimeString(),
                'supportEmail' => 'support@apispi.com',
                'securityUrl' => rtrim(config('app.url'), '/').'/profile?tab=account',
            ],
        );
    }
}
